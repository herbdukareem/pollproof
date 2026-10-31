<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Actions on several rows at once, from the tables' checkboxes. */
class BulkActionsTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_agents_are_released_deactivated_and_reactivated_together(): void
    {
        $a = $this->agentFor('PU1');
        $b = $this->agentFor('PU2');
        $a->createToken('app');
        $this->actingAsUser($this->staff(Role::SituationRoom));

        $this->postJson('/api/v1/agents/bulk', ['ids' => [$a->id, $b->id], 'action' => 'release'])->assertOk()->assertJson(['done' => 2, 'skipped' => 0]);
        $this->assertTrue($a->fresh()->is_reserve);
        $this->assertNull($b->fresh()->polling_unit_id);

        $this->postJson('/api/v1/agents/bulk', ['ids' => [$a->id, $b->id, 999999], 'action' => 'deactivate'])->assertOk()->assertJson(['done' => 2, 'skipped' => 1]);
        $this->assertFalse($a->fresh()->isActive());
        $this->assertSame(0, $a->tokens()->count());

        $this->postJson('/api/v1/agents/bulk', ['ids' => [$a->id], 'action' => 'reactivate'])->assertOk()->assertJson(['done' => 1]);
        $this->assertTrue($a->fresh()->isActive());

        $this->actingAsUser($this->staff(Role::Verifier));
        $this->postJson('/api/v1/agents/bulk', ['ids' => [$a->id], 'action' => 'release'])->assertForbidden();
    }

    public function test_check_ins_are_approved_together_within_the_supervisors_ward(): void
    {
        $mine = $this->agentFor('PU1');
        $other = $this->agentFor('PU4');
        $make = fn ($agent) => CheckIn::create(['campaign_id' => $this->campaign->id, 'user_id' => $agent->id, 'polling_unit_id' => $agent->polling_unit_id, 'channel' => 'ussd', 'status' => CheckIn::PENDING_REVIEW, 'captured_at' => now()]);
        $c1 = $make($mine);
        $c2 = $make($other);
        $ws = $this->staff(Role::WardSupervisor, ['ward_id' => $mine->ward_id, 'lga_id' => $this->lgas['L1']->id]);
        $this->actingAsUser($ws);

        $this->postJson('/api/v1/check-ins/bulk-review', ['ids' => [$c1->id, $c2->id], 'decision' => 'approve'])->assertOk()->assertJson(['done' => $mine->ward_id === $other->ward_id ? 2 : 1]);
        $this->assertSame(CheckIn::APPROVED, $c1->fresh()->status);
        $this->postJson('/api/v1/check-ins/bulk-review', ['ids' => [$c1->id], 'decision' => 'reject'])->assertJson(['done' => 0, 'skipped' => 1]);
    }

    public function test_supervisors_are_taken_off_their_posts_together(): void
    {
        $make = fn ($w, $code) => User::factory()->role(Role::WardSupervisor)->create(['campaign_id' => $this->campaign->id, 'ward_id' => $this->wards[$w]->id, 'lga_id' => $this->wards[$w]->lga_id, 'code' => $code]);
        $s1 = $make('L1', 'WS-001');
        $s2 = $make('L2', 'WS-002');
        $this->actingAsUser($this->staff(Role::Dg));
        $this->postJson('/api/v1/people/bulk-unassign', ['ids' => [$s1->id, $s2->id]])->assertOk()->assertJson(['done' => 2]);
        $this->assertNull($s1->fresh()->ward_id);
    }
}
