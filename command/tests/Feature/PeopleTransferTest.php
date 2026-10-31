<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Moving people between agent and supervisor posts from the Supervisors page. */
class PeopleTransferTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        $this->actingAsUser($this->staff(Role::SituationRoom));
    }

    private function wardSupervisor(string $ward, string $phone): User
    {
        return User::factory()->role(Role::WardSupervisor)->create(['campaign_id' => $this->campaign->id, 'ward_id' => $this->wards[$ward]->id, 'lga_id' => $this->wards[$ward]->lga_id, 'phone' => $phone, 'code' => 'WS-0'.substr($phone, -2)]);
    }

    public function test_search_finds_agents_and_supervisors_with_their_post(): void
    {
        $agent = $this->agentFor('PU1', ['name' => 'Amina Bello', 'phone' => '08031112222']);
        $this->wardSupervisor('L1', '08035556666');

        $this->getJson('/api/v1/setup/people?search=amina')->assertOk()
            ->assertJsonPath('data.0.id', $agent->id)->assertJsonPath('data.0.post', 'Agent at PU1')->assertJsonPath('data.0.role', 'agent');
        $this->getJson('/api/v1/setup/people?search=0803 555')->assertJsonPath('data.0.role', 'ward_supervisor');
        $this->getJson('/api/v1/setup/people?search=+2348031112222')->assertJsonPath('data.0.id', $agent->id);
    }

    public function test_an_agent_becomes_ward_supervisor_and_the_old_supervisor_becomes_a_reserve(): void
    {
        $agent = $this->agentFor('PU1');
        $agent->createToken('app');
        $old = $this->wardSupervisor('L1', '08035556666');
        $ward = $this->wards['L1'];

        $this->postJson('/api/v1/supervisors', ['user_id' => $agent->id, 'level' => 'ward', 'ward_id' => $ward->id, 'previous' => 'reserve'])
            ->assertCreated()->assertJsonPath('freed_unit', 'PU1')->assertJsonPath('replaced', fn ($c) => str_starts_with($c, 'AG-'))->assertJsonPath('previous_to', 'reserve');

        $agent->refresh();
        $this->assertSame([Role::WardSupervisor, $ward->id, null], [$agent->role, $agent->ward_id, $agent->polling_unit_id]);
        $this->assertStringStartsWith('WS-', $agent->code);
        $this->assertSame(0, $agent->tokens()->count(), 'signed out so the app opens the supervisor screens');

        $old->refresh();
        $this->assertSame([Role::Agent, true, $ward->id], [$old->role, $old->is_reserve, $old->ward_id]);
        $this->assertTrue(AuditLog::where('action', 'supervisor.assigned')->exists());
        $this->getJson('/api/v1/setup/supervisors?lga_id='.$ward->lga_id)->assertJsonPath('selected.wards.0.supervisor.id', $agent->id);
    }

    public function test_a_supervisor_goes_back_to_being_an_agent_at_a_unit(): void
    {
        $sup = $this->wardSupervisor('L1', '08035556666');
        $current = $this->agentFor('PU2');

        $this->postJson("/api/v1/people/{$sup->id}/make-agent", ['polling_unit_id' => $this->units['PU2']->id])->assertOk()
            ->assertJsonPath('post', 'Agent at PU2')->assertJsonPath('replaced.code', $current->code);
        $this->assertSame([Role::Agent, $this->units['PU2']->id], [$sup->fresh()->role, $sup->fresh()->polling_unit_id]);
        $this->assertTrue($current->fresh()->is_reserve);
        $this->assertNull($current->fresh()->polling_unit_id);

        // Or as a reserve in a ward, with no unit.
        $other = $this->wardSupervisor('L2', '08037778888');
        $this->postJson("/api/v1/people/{$other->id}/make-agent", ['ward_id' => $this->wards['L2']->id])->assertOk()->assertJsonPath('post', fn ($p) => str_starts_with($p, 'Reserve agent'));
    }

    public function test_a_new_person_replaces_the_lga_supervisor_and_a_supervisor_can_leave_a_post(): void
    {
        $lga = $this->lgas['L1'];
        $this->postJson('/api/v1/supervisors', ['name' => 'Bala Usman', 'phone' => '08039990000', 'level' => 'lga', 'lga_id' => $lga->id])->assertCreated();
        $first = User::where('phone', '08039990000')->sole();
        $this->postJson('/api/v1/supervisors', ['name' => 'Joy Ade', 'phone' => '08039990001', 'level' => 'lga', 'lga_id' => $lga->id])->assertCreated()->assertJsonPath('replaced', $first->code)->assertJsonPath('previous_to', 'unassigned');
        $this->assertNull($first->fresh()->lga_id);

        $joy = User::where('phone', '08039990001')->sole();
        $this->postJson("/api/v1/people/{$joy->id}/unassign")->assertOk()->assertJsonPath('post', 'LGA Supervisor, no post');
    }

    public function test_staff_and_deactivated_people_cannot_be_moved(): void
    {
        $verifier = $this->staff(Role::Verifier);
        $this->postJson('/api/v1/supervisors', ['user_id' => $verifier->id, 'level' => 'ward', 'ward_id' => $this->wards['L1']->id])->assertJsonValidationErrors('user_id');
        $gone = $this->agentFor('PU3', ['deactivated_at' => now()]);
        $this->postJson('/api/v1/supervisors', ['user_id' => $gone->id, 'level' => 'ward', 'ward_id' => $this->wards['L1']->id])->assertJsonValidationErrors('user_id');
    }
}
