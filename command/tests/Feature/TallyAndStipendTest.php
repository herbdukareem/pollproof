<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class TallyAndStipendTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_tally_applies_the_25_percent_in_two_thirds_of_lgas_rule(): void
    {
        // L1: A 50%, L2: A 30%, L3: A 10%  -> A meets 25% in 2 of 3 LGAs (needs 2).
        $this->submitResult('PU1', ['A' => 50, 'B' => 40, 'C' => 10], 100);
        $this->submitResult('PU3', ['A' => 30, 'B' => 60, 'C' => 10], 100);
        $this->submitResult('PU5', ['A' => 10, 'B' => 80, 'C' => 10], 100);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $tally = $this->getJson('/api/v1/tally')->assertOk()->json();

        $this->assertSame(6, $tally['units']);
        $this->assertSame(3, $tally['units_reported']);
        $this->assertSame(2, $tally['lgas_required']);

        $byParty = collect($tally['candidates'])->keyBy('party');
        $this->assertSame(180, $byParty['B']['votes']);
        $this->assertTrue($byParty['B']['leads']);
        $this->assertSame(3, $byParty['B']['lgas_meeting_threshold']);
        $this->assertSame(2, $byParty['A']['lgas_meeting_threshold']);
        $this->assertTrue($byParty['A']['meets_spread']);
        $this->assertFalse($byParty['C']['meets_spread']);
    }

    public function test_sent_back_results_are_left_out_of_the_tally(): void
    {
        $id = $this->submitResult('PU1', ['A' => 50, 'B' => 40, 'C' => 10], 100);
        $this->actingAsUser($this->staff(Role::Verifier));
        $this->postJson("/api/v1/results/{$id}/send-back", ['note' => 'Wrong sheet']);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/tally')->assertJsonPath('units_reported', 0);
    }

    public function test_stipends_need_a_verified_check_in_and_a_result(): void
    {
        $paid = $this->agentFor('PU1');
        $noResult = $this->agentFor('PU2');
        $outside = $this->agentFor('PU3');
        $this->agentFor('PU4'); // never turned up

        foreach ([[$paid, CheckIn::VERIFIED], [$noResult, CheckIn::VERIFIED], [$outside, CheckIn::PENDING_REVIEW]] as [$agent, $status]) {
            CheckIn::create(['campaign_id' => $this->campaign->id, 'user_id' => $agent->id, 'polling_unit_id' => $agent->polling_unit_id,
                'latitude' => 9.6, 'longitude' => 6.5, 'accuracy_m' => 5, 'status' => $status, 'captured_at' => now()]);
        }
        $this->submitResult('PU1', ['A' => 1], 10);
        $this->submitResult('PU3', ['A' => 1], 10);

        $this->actingAsUser($this->staff(Role::Finance));
        $res = $this->getJson('/api/v1/stipends')->assertOk();
        $res->assertJsonPath('eligible', 1)->assertJsonPath('on_hold', 3);
        $reasons = collect($res->json('agents'))->pluck('reason', 'agent_code');
        $this->assertNull($reasons['AG-PU1']);
        $this->assertSame('No signed result yet', $reasons['AG-PU2']);
        $this->assertSame('Checked in outside unit area', $reasons['AG-PU3']);
        $this->assertSame('Absent or never checked in', $reasons['AG-PU4']);

        $csv = $this->get('/api/v1/stipends/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('AG-PU1', $csv);
        $this->assertStringNotContainsString('AG-PU2', $csv);
    }

    public function test_a_supervisor_sends_a_reserve_to_an_empty_unit(): void
    {
        $absent = $this->agentFor('PU1');
        $reserve = User::factory()->role(Role::Agent)->create(['campaign_id' => $this->campaign->id, 'is_reserve' => true, 'code' => 'AG-R1']);
        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id, 'lga_id' => $this->lgas['L1']->id]));

        $this->postJson("/api/v1/agents/{$reserve->id}/assign", ['polling_unit_id' => $this->units['PU1']->id])
            ->assertOk()->assertJsonPath('replaced_agent_id', $absent->id);
        $this->assertNull($absent->fresh()->polling_unit_id);
        $this->assertSame($this->units['PU1']->id, $reserve->fresh()->polling_unit_id);

        $this->postJson("/api/v1/agents/{$reserve->id}/assign", ['polling_unit_id' => $this->units['PU3']->id])->assertForbidden();
    }
}
