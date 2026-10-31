<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\ResultSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Tables open latest first, and a column header can sort them on the server. */
class SortingTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    private function signedResultFor(string $unit, string $at): void
    {
        $agent = $this->agentFor($unit);
        ResultSubmission::create([
            'campaign_id' => $this->campaign->id, 'polling_unit_id' => $agent->polling_unit_id, 'user_id' => $agent->id, 'is_current' => true,
            'accredited' => 300, 'rejected' => 2, 'total_valid' => 250, 'total_cast' => 252, 'captured_at' => $at, 'payload_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_units_open_with_the_latest_result_first_and_sort_by_column(): void
    {
        $this->signedResultFor('PU2', '2027-02-06T15:00:00Z');
        $this->signedResultFor('PU1', '2027-02-06T16:30:00Z');
        $this->actingAsUser($this->staff(Role::SituationRoom));

        $codes = fn (array $q = []) => collect($this->getJson('/api/v1/units?'.http_build_query($q))->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['PU1', 'PU2'], array_slice($codes(), 0, 2), 'latest result first, units without one after');
        $this->assertSame(['PU2', 'PU1'], array_slice($codes(['sort' => 'result', 'dir' => 'asc']), 0, 2));
        $byCode = $codes(['sort' => 'code', 'dir' => 'desc']);
        $this->assertSame(collect($byCode)->sortDesc(SORT_NATURAL)->values()->all(), $byCode);
        $this->getJson('/api/v1/units?sort=nonsense')->assertJsonValidationErrors('sort');
    }

    public function test_audit_log_roster_and_incidents_open_latest_first(): void
    {
        $dg = $this->actingAsUser($this->staff(Role::Dg));
        $first = $this->agentFor('PU1');
        $last = $this->agentFor('PU2');

        $this->getJson('/api/v1/setup/roster')->assertOk()->assertJsonPath('data.0.id', $last->id);
        $this->getJson('/api/v1/setup/roster?sort=code&dir=asc')->assertJsonPath('data.0.code', collect([$first->code, $last->code])->sort(SORT_NATURAL)->first());

        $ids = AuditLog::where('campaign_id', $this->campaign->id)->orderBy('id')->pluck('id');
        if ($ids->count() > 1) {
            $this->getJson('/api/v1/audit-logs')->assertJsonPath('data.0.id', $ids->last());
            $this->getJson('/api/v1/audit-logs?sort=time&dir=asc')->assertJsonPath('data.0.id', $ids->first());
        }
        $this->getJson('/api/v1/audit-logs?sort=action&dir=asc')->assertOk();
        $this->getJson('/api/v1/audit-logs?sort=user&dir=desc')->assertOk();

        $old = Incident::create(['campaign_id' => $this->campaign->id, 'user_id' => $first->id, 'polling_unit_id' => $this->units['PU1']->id, 'type' => 'disruption', 'severity' => 'high', 'description' => 'Old', 'occurred_at' => '2027-02-06 08:00:00', 'status' => 'open']);
        $new = Incident::create(['campaign_id' => $this->campaign->id, 'user_id' => $last->id, 'polling_unit_id' => $this->units['PU2']->id, 'type' => 'other', 'severity' => 'low', 'description' => 'New', 'occurred_at' => '2027-02-06 12:00:00', 'status' => 'open']);
        $this->getJson('/api/v1/incidents')->assertJsonPath('data.0.id', $new->id);
        $this->getJson('/api/v1/incidents?sort=severity')->assertJsonPath('data.0.id', $old->id);
    }
}
