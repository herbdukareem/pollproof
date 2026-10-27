<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Behaviour the agent app relies on when the network is unreliable. */
class AgentAppTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_resending_the_same_signed_result_returns_the_first_record(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);
        $body = $this->signedResult($device, $sk, 'PU1', ['A' => 10, 'B' => 5], 20);

        $first = $this->postJson('/api/v1/results', $body)->assertCreated()->json('id');
        $this->postJson('/api/v1/results', $body)->assertOk()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('result_submissions', 1);
    }

    public function test_retried_check_ins_and_incidents_are_not_duplicated(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $u = $this->units['PU1'];
        $ref = (string) Str::uuid();
        $ci = ['latitude' => $u->latitude, 'longitude' => $u->longitude, 'accuracy_m' => 5, 'captured_at' => now()->toIso8601String(), 'client_ref' => $ref];
        $id = $this->postJson('/api/v1/check-ins', $ci)->assertCreated()->json('id');
        $this->postJson('/api/v1/check-ins', $ci)->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('check_ins', 1);

        $inc = ['type' => 'other', 'severity' => 'low', 'description' => 'Queue is long', 'occurred_at' => now()->toIso8601String(), 'client_ref' => (string) Str::uuid()];
        $this->postJson('/api/v1/incidents', $inc)->assertCreated();
        $this->postJson('/api/v1/incidents', $inc)->assertOk();
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_me_tells_the_app_the_rules_its_supervisor_and_todays_progress(): void
    {
        config(['pollproof.sms_number' => '33811']);
        $this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id, 'name' => 'Ward Lead', 'phone' => '08031234567']);
        $agent = $this->agentFor('PU1');
        $this->submitResult('PU1', ['A' => 10, 'B' => 5], 20);
        $this->actingAsUser($agent);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('rules.geofence_radius_m', 100)
            ->assertJsonPath('rules.sms_number', '33811')
            ->assertJsonPath('supervisor.name', 'Ward Lead')
            ->assertJsonPath('supervisor.phone', '08031234567')
            ->assertJsonPath('today.check_in', null)
            ->assertJsonPath('today.result.total_cast', 15)
            ->assertJsonPath('today.result.status', 'pending_review');
    }
}
