<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class ListActionsTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_alerts_filter_by_severity_and_type_with_counts_for_each_filter(): void
    {
        $alerts = app(AlertService::class);
        $alerts->raise($this->campaign->id, 'overvote', 'high', 'a1', 'PU1: votes exceed accreditation', 'Detail', $this->units['PU1']);
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'a2', 'PU2: result not on IReV', 'Detail', $this->units['PU2']);
        $resolved = $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'a3', 'PU3: result not on IReV', 'Detail', $this->units['PU3']);
        $resolved->update(['status' => 'resolved']);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/alerts?per_page=5')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('counts.status.open', 2)
            ->assertJsonPath('counts.status.resolved', 1)
            ->assertJsonPath('counts.severity.high', 1)
            ->assertJsonPath('data.0.link', '/irev');
        $this->getJson('/api/v1/alerts?severity=high')->assertJsonPath('total', 1)->assertJsonPath('data.0.type', 'overvote')
            ->assertJsonPath('counts.severity.medium', 1);
        $this->getJson('/api/v1/alerts?status=resolved&search=PU3')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/alerts?status=all&type=irev_missing')->assertJsonPath('total', 2);
        $this->getJson('/api/v1/nav-counts')->assertJsonPath('alerts', 2);
    }

    public function test_situation_room_can_edit_release_deactivate_and_reactivate_an_agent(): void
    {
        $agent = $this->agentFor('PU1', ['phone' => '08030000001']);
        $agent->createToken('app');
        $this->actingAsUser($this->staff(Role::SituationRoom));

        $this->patchJson("/api/v1/agents/{$agent->id}", ['name' => 'Amina Bello', 'phone' => '08030000099'])->assertOk()
            ->assertJsonPath('phone', '08030000099');
        $this->assertSame('Amina Bello', $agent->fresh()->name);
        $this->assertSame(0, $agent->tokens()->count(), 'a new phone number signs the agent out');

        $this->patchJson("/api/v1/agents/{$agent->id}", ['release' => true])->assertOk();
        $this->assertNull($agent->fresh()->polling_unit_id);
        $this->assertTrue($agent->fresh()->is_reserve);

        $this->agentFor('PU2');
        $this->postJson("/api/v1/agents/{$agent->id}/assign", ['polling_unit_id' => $this->units['PU1']->id])->assertOk();
        $agent->createToken('app');
        $this->patchJson("/api/v1/agents/{$agent->id}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->assertNull($agent->fresh()->polling_unit_id, 'deactivating frees the unit');
        $this->assertSame(0, $agent->tokens()->count());
        $this->getJson('/api/v1/agents')->assertJsonPath('summary.deactivated', 1)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/agents?status=deactivated')->assertJsonPath('data.0.id', $agent->id);

        $this->patchJson("/api/v1/agents/{$agent->id}", ['active' => true])->assertOk();
        $this->assertTrue($agent->fresh()->isActive());
        $this->assertSame(['agent.updated', 'agent.released', 'agent.assigned', 'agent.deactivated', 'agent.reactivated'],
            AuditLog::where('action', 'like', 'agent.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_agent_phone_must_be_valid_and_unused_and_supervisors_cannot_edit_agents(): void
    {
        $agent = $this->agentFor('PU1');
        $other = $this->agentFor('PU2', ['phone' => '08030000002']);
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->patchJson("/api/v1/agents/{$agent->id}", ['phone' => '12'])->assertStatus(422);
        $this->patchJson("/api/v1/agents/{$agent->id}", ['phone' => $other->phone])->assertStatus(422);

        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id, 'lga_id' => $this->lgas['L1']->id]));
        $this->patchJson("/api/v1/agents/{$agent->id}", ['active' => false])->assertForbidden();
    }

    public function test_agent_list_searches_and_filters_by_lga(): void
    {
        $this->agentFor('PU1', ['name' => 'Musa Garba']);
        $this->agentFor('PU3', ['name' => 'Hauwa Sani']);
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/agents?search=hauwa')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Hauwa Sani');
        $lga = $this->units['PU1']->ward->lga_id;
        $this->getJson("/api/v1/agents?lga_id={$lga}")->assertJsonPath('data.0.name', 'Musa Garba')
            ->assertJsonPath('summary.absent', 2);
    }

    public function test_lists_take_a_page_size(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $this->getJson('/api/v1/units?per_page=10')->assertOk()->assertJsonPath('per_page', 10);
        $this->getJson('/api/v1/incidents?per_page=10')->assertOk()->assertJsonPath('per_page', 10)->assertJsonStructure(['counts' => ['types', 'status']]);
        $this->getJson('/api/v1/audit-logs?per_page=10&search=x')->assertOk()->assertJsonPath('per_page', 10);
        $this->getJson('/api/v1/results-queue?kind=flagged')->assertOk()->assertJsonStructure(['data', 'total', 'counts' => ['all', 'flagged', 'corrections']]);
        $this->getJson('/api/v1/setup/roster?per_page=10')->assertOk()->assertJsonPath('per_page', 10);
    }
}
