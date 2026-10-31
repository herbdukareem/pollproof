<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Services\AuditLogger;
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
        $result = ResultSubmission::create([
            'campaign_id' => $this->campaign->id, 'polling_unit_id' => $agent->polling_unit_id, 'user_id' => $agent->id, 'is_current' => true,
            'accredited' => 300, 'rejected' => 2, 'total_valid' => 250, 'total_cast' => 252, 'captured_at' => $at, 'payload_hash' => str_repeat('a', 64),
        ]);
        app(AuditLogger::class)->log($agent, 'result.submitted', $result);
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

    public function test_lists_built_in_php_sort_on_the_server_too(): void
    {
        $this->signedResultFor('PU1', '2027-02-06T15:00:00Z');
        $this->signedResultFor('PU2', '2027-02-06T16:30:00Z');
        $this->agentFor('PU3');
        $this->actingAsUser($this->staff(Role::Dg));

        $agents = fn (string $q = '') => collect($this->getJson('/api/v1/agents'.$q)->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['AG-PU2', 'AG-PU1', 'AG-PU3'], $agents(), 'latest activity first, none last');
        $this->assertSame(['AG-PU1', 'AG-PU2', 'AG-PU3'], $agents('?sort=result&dir=asc'), 'no result still last');
        $this->assertSame(['AG-PU3', 'AG-PU2', 'AG-PU1'], $agents('?sort=code&dir=desc'));
        $this->getJson('/api/v1/agents?sort=phone')->assertJsonValidationErrors('sort');

        $this->getJson('/api/v1/stipends')->assertJsonPath('agents.0.agent_code', 'AG-PU2');
        $this->getJson('/api/v1/stipends?sort=code&dir=asc')->assertJsonPath('agents.0.agent_code', 'AG-PU1');

        $names = collect($this->getJson('/api/v1/users?sort=name&dir=asc')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(collect($names)->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values()->all(), $names);
        $this->getJson('/api/v1/users')->assertJsonPath('data.0.id', User::whereIn('role', ['dg'])->max('id'));

        $lgas = collect($this->getJson('/api/v1/readiness?sort=name&dir=desc')->assertOk()->json('lgas'))->pluck('name')->all();
        $this->assertSame(collect($lgas)->sortDesc()->values()->all(), $lgas);
        $sup = collect($this->getJson('/api/v1/setup/supervisors?sort=name&dir=asc')->assertOk()->json('lgas'))->pluck('name')->all();
        $this->assertSame(collect($sup)->sort()->values()->all(), $sup);
        $this->getJson('/api/v1/collation-entries?sort=status&dir=asc')->assertOk();
    }

    public function test_latest_means_the_latest_action_recorded_not_the_time_on_the_record(): void
    {
        // Election-day results dated in the future, as in the demo, then a check-in approved now.
        $this->signedResultFor('PU1', '2027-02-06T21:29:00Z');
        $agent = $this->agentFor('PU2');
        $ws = $this->staff(Role::WardSupervisor, ['ward_id' => $agent->ward_id, 'lga_id' => $this->lgas['L1']->id]);

        $this->actingAsUser($agent);
        $u = $this->units['PU2'];
        $id = $this->postJson('/api/v1/check-ins', ['latitude' => $u->latitude + 0.0037, 'longitude' => $u->longitude, 'accuracy_m' => 10, 'captured_at' => '2027-02-06T08:00:00Z', 'request_review' => true])->json('id');
        $this->actingAsUser($this->staff(Role::Dg));
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.code', 'AG-PU2');

        // The result is checked again later: that agent moves back to the top.
        app(AuditLogger::class)->log($ws, 'result.approved', ResultSubmission::sole());
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.code', 'AG-PU1');

        // Approving the check-in is the newest action now.
        $this->actingAsUser($ws);
        $this->postJson("/api/v1/check-ins/{$id}/review", ['decision' => 'approve'])->assertOk();
        $this->actingAsUser($this->staff(Role::Dg));
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.code', 'AG-PU2')->assertJsonPath('data.0.status', 'present');
    }
}
