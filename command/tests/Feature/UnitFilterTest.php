<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\CheckIn;
use App\Models\IrevEntry;
use App\Models\ResultSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** The extra filters on the Units table. */
class UnitFilterTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    private function codes(string $query): array
    {
        return collect($this->getJson('/api/v1/units?'.$query)->assertOk()->json('data'))->pluck('code')->sort()->values()->all();
    }

    public function test_units_filter_by_agent_result_irev_and_channel(): void
    {
        $this->buildElection();
        $a1 = $this->agentFor('PU1');
        $this->agentFor('PU2');
        CheckIn::create(['campaign_id' => $this->campaign->id, 'user_id' => $a1->id, 'polling_unit_id' => $this->units['PU1']->id, 'status' => CheckIn::VERIFIED, 'captured_at' => now(), 'latitude' => 1, 'longitude' => 1, 'accuracy_m' => 5]);
        $result = fn ($unit, $agent, $status, $channel) => ResultSubmission::create([
            'campaign_id' => $this->campaign->id, 'polling_unit_id' => $this->units[$unit]->id, 'user_id' => $agent->id, 'is_current' => true, 'status' => $status, 'channel' => $channel,
            'accredited' => 10, 'rejected' => 0, 'total_valid' => 10, 'total_cast' => 10, 'captured_at' => now(), 'payload_hash' => str_repeat('a', 64),
        ]);
        $result('PU1', $a1, ResultSubmission::APPROVED, 'app');
        $result('PU2', $this->units['PU2']->agent, ResultSubmission::PENDING_REVIEW, 'ussd');
        $entry = IrevEntry::create(['campaign_id' => $this->campaign->id, 'polling_unit_id' => $this->units['PU2']->id, 'votes' => ['A' => 1], 'seen_at' => now(), 'entered_by' => $a1->id]);
        Alert::create(['campaign_id' => $this->campaign->id, 'type' => 'irev_mismatch', 'severity' => 'high', 'dedupe_key' => 'irev_mismatch:unit:'.$this->units['PU2']->id, 'title' => 'x', 'detail' => 'x', 'status' => 'open', 'opened_at' => now()]);
        $this->actingAsUser($this->staff(Role::SituationRoom));

        $this->assertSame(['PU1'], $this->codes('agent=present'));
        $this->assertSame(['PU2'], $this->codes('agent=absent'));
        $this->assertNotContains('PU1', $this->codes('agent=none'));
        $this->assertSame(['PU1'], $this->codes('result=verified'));
        $this->assertSame(['PU2'], $this->codes('result=pending'));
        $this->assertNotContains('PU1', $this->codes('result=none'));
        $this->assertSame(['PU2'], $this->codes('irev=differs'));
        $this->assertNotContains('PU2', $this->codes('irev=unchecked'));
        $this->assertSame([], $this->codes('irev=match'));
        $this->assertSame(['PU2'], $this->codes('channel=ussd'));
        $this->assertSame(['PU1'], $this->codes('result=verified&channel=app&agent=present'));
        $this->getJson('/api/v1/units?irev=maybe')->assertJsonValidationErrors('irev');
    }
}
