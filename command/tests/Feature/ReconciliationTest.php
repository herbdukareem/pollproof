<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        $this->submitResult('PU1', ['A' => 200, 'B' => 150, 'C' => 20], 400, 5);
        $this->submitResult('PU2', ['A' => 100, 'B' => 120, 'C' => 10], 300, 3);
    }

    public function test_an_inflated_ward_sheet_raises_a_high_alert_with_the_difference(): void
    {
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $res = $this->postJson('/api/v1/collation-entries', [
            'level' => 'ward', 'scope_id' => $this->wards['L1']->id,
            'votes' => ['A' => 300, 'B' => 430, 'C' => 30], 'rejected' => 8, 'announced_at' => '2027-02-06T20:07:00Z',
        ])->assertCreated();

        $res->assertJsonPath('comparison.differences', ['B' => 160])
            ->assertJsonPath('comparison.coverage_complete', true);

        $alert = Alert::where('type', 'collation_mismatch')->sole();
        $this->assertSame('high', $alert->severity);
        $this->assertStringContainsString('B +160', $alert->detail);
        $this->assertSame('high', Alert::where('type', 'overvote')->sole()->severity); // 768 cast vs 700 accredited
    }

    public function test_a_matching_sheet_raises_nothing(): void
    {
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson('/api/v1/collation-entries', [
            'level' => 'ward', 'scope_id' => $this->wards['L1']->id,
            'votes' => ['A' => 300, 'B' => 270, 'C' => 30], 'rejected' => 8, 'announced_at' => '2027-02-06T20:07:00Z',
        ])->assertCreated()->assertJsonPath('comparison.differences', []);

        $this->assertSame(0, Alert::where('status', 'open')->count());
    }

    public function test_a_collation_agent_can_only_enter_their_own_centre(): void
    {
        $this->actingAsUser($this->staff(Role::CollationAgent, ['ward_id' => $this->wards['L2']->id, 'lga_id' => $this->lgas['L2']->id]));
        $this->postJson('/api/v1/collation-entries', [
            'level' => 'ward', 'scope_id' => $this->wards['L1']->id, 'votes' => ['A' => 1], 'announced_at' => '2027-02-06T20:07:00Z',
        ])->assertForbidden();
    }

    public function test_a_resolved_alert_stays_resolved_when_nothing_changes(): void
    {
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson('/api/v1/collation-entries', [
            'level' => 'ward', 'scope_id' => $this->wards['L1']->id, 'votes' => ['A' => 300, 'B' => 430, 'C' => 30], 'rejected' => 8, 'announced_at' => '2027-02-06T20:07:00Z',
        ]);
        $alert = Alert::where('type', 'collation_mismatch')->sole();

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->patchJson("/api/v1/alerts/{$alert->id}", ['status' => 'resolved'])->assertOk();

        // A correction in the same ward re-checks the sheet. Same figures, same problem: it must not reopen.
        $agent = User::where('polling_unit_id', $this->units['PU1']->id)->first();
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);
        $current = ResultSubmission::where('polling_unit_id', $this->units['PU1']->id)->where('is_current', true)->value('id');
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 200, 'B' => 150, 'C' => 20], 400, 5, $current))->assertCreated();
        $this->assertSame('resolved', $alert->fresh()->status);

        // A correction that changes the difference is a new problem and reopens it.
        $current = ResultSubmission::where('polling_unit_id', $this->units['PU1']->id)->where('is_current', true)->value('id');
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 200, 'B' => 160, 'C' => 20], 400, 5, $current))->assertCreated();
        $this->assertSame('open', $alert->fresh()->status);
    }

    public function test_irev_mismatch_and_missing_uploads_raise_alerts(): void
    {
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson('/api/v1/irev-entries', [
            'polling_unit_id' => $this->units['PU1']->id, 'not_on_irev' => false,
            'votes' => ['A' => 200, 'B' => 250, 'C' => 20], 'seen_at' => '2027-02-06T17:10:00Z',
        ])->assertCreated()->assertJsonPath('comparison.differences', ['B' => 100]);
        $this->assertSame(1, Alert::where('type', 'irev_mismatch')->count());

        $this->postJson('/api/v1/irev-entries', [
            'polling_unit_id' => $this->units['PU2']->id, 'not_on_irev' => true, 'seen_at' => '2027-02-06T19:31:00Z',
        ])->assertCreated()->assertJsonPath('comparison.status', 'not_on_irev');
        $this->assertSame('medium', Alert::where('type', 'irev_missing')->sole()->severity);
    }

    public function test_evidence_bundle_brings_the_records_together(): void
    {
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson('/api/v1/collation-entries', [
            'level' => 'ward', 'scope_id' => $this->wards['L1']->id, 'votes' => ['A' => 300, 'B' => 430, 'C' => 30], 'rejected' => 8, 'announced_at' => '2027-02-06T20:07:00Z',
        ]);
        $this->postJson('/api/v1/irev-entries', ['polling_unit_id' => $this->units['PU1']->id, 'not_on_irev' => true, 'seen_at' => '2027-02-06T18:00:00Z'])->assertCreated();

        $this->actingAsUser($this->staff(Role::Legal));
        $this->getJson('/api/v1/units/'.$this->units['PU1']->id.'/evidence')
            ->assertOk()
            ->assertJsonPath('polling_unit.code', 'PU1')
            ->assertJsonPath('results.0.signature_valid', true)
            ->assertJsonPath('collation.0.comparison.differences', ['B' => 160])
            ->assertJsonPath('irev.comparison.status', 'not_on_irev');
    }
}
