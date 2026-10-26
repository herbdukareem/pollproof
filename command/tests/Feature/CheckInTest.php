<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CheckIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_check_in_inside_the_unit_area_is_verified(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $unit = $this->units['PU1'];

        $this->postJson('/api/v1/check-ins', [
            'latitude' => $unit->latitude + 0.0002, 'longitude' => $unit->longitude, 'accuracy_m' => 7, 'captured_at' => '2027-02-06T08:02:00Z',
        ])->assertCreated()->assertJsonPath('status', 'verified');
    }

    public function test_check_in_far_from_the_unit_is_refused_unless_review_is_requested(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        $far = ['latitude' => $this->units['PU1']->latitude + 0.0037, 'longitude' => $this->units['PU1']->longitude, 'accuracy_m' => 10, 'captured_at' => '2027-02-06T08:02:00Z'];

        $this->postJson('/api/v1/check-ins', $far)->assertUnprocessable()->assertJsonPath('status', 'outside_area');
        $this->assertSame(0, CheckIn::count());

        $id = $this->postJson('/api/v1/check-ins', $far + ['request_review' => true])
            ->assertCreated()->assertJsonPath('status', 'pending_review')->json('id');

        $other = $this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L2']->id, 'lga_id' => $this->lgas['L2']->id]);
        $this->actingAsUser($other);
        $this->postJson("/api/v1/check-ins/{$id}/review", ['decision' => 'approve'])->assertForbidden();

        $own = $this->staff(Role::WardSupervisor, ['ward_id' => $agent->ward_id, 'lga_id' => $this->lgas['L1']->id]);
        $this->actingAsUser($own);
        $this->postJson("/api/v1/check-ins/{$id}/review", ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_weak_gps_is_not_trusted_even_near_the_unit(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $this->postJson('/api/v1/check-ins', [
            'latitude' => $this->units['PU1']->latitude, 'longitude' => $this->units['PU1']->longitude, 'accuracy_m' => 85, 'captured_at' => '2027-02-06T08:02:00Z',
        ])->assertUnprocessable();
    }
}
