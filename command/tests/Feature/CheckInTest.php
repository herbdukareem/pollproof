<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\CheckIn;
use App\Models\Notice;
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

    public function test_agents_check_in_once_a_day_and_afresh_the_next_day(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $u = $this->units['PU1'];
        $at = fn (string $iso) => ['latitude' => $u->latitude, 'longitude' => $u->longitude, 'accuracy_m' => 7, 'captured_at' => $iso];

        $first = $this->postJson('/api/v1/check-ins', $at('2027-02-05T08:00:00Z'))->assertCreated()->json('id');
        $this->postJson('/api/v1/check-ins', $at('2027-02-05T15:00:00Z'))->assertOk()
            ->assertJsonPath('already', true)->assertJsonPath('id', $first)->assertJsonPath('message', 'You are already checked in at PU1 today.');
        $this->assertSame(1, CheckIn::count());

        $this->postJson('/api/v1/check-ins', $at('2027-02-06T07:45:00Z'))->assertCreated()->assertJsonPath('status', 'verified');
        $this->assertSame(2, CheckIn::count());
    }

    public function test_a_check_in_to_confirm_notifies_supervisors_and_a_repeat_replaces_it(): void
    {
        $agent = $this->agentFor('PU1');
        $ws = $this->staff(Role::WardSupervisor, ['ward_id' => $agent->ward_id, 'lga_id' => $this->lgas['L1']->id]);
        $room = $this->staff(Role::SituationRoom);
        $u = $this->units['PU1'];
        $far = ['latitude' => $u->latitude + 0.0037, 'longitude' => $u->longitude, 'accuracy_m' => 10, 'captured_at' => now()->toIso8601String(), 'request_review' => true];

        $this->actingAsUser($agent);
        $first = $this->postJson('/api/v1/check-ins', $far)->assertCreated()->json('id');
        $alert = Alert::where('type', 'checkin_review')->sole();
        $this->assertSame(['open', 'PU1: check-in to confirm'], [$alert->status, $alert->title]);
        $this->assertSame(1, Notice::where('user_id', $ws->id)->count());
        $this->assertSame(1, Notice::where('user_id', $room->id)->count());

        $this->actingAsUser($room);
        $this->getJson('/api/v1/nav-counts')->assertJsonPath('checkins', 1)
            ->assertJsonPath('checkins_newest.id', $first)->assertJsonPath('checkins_newest.unit', 'PU1')->assertJsonPath('checkins_newest.channel', 'app');

        // Still outside: the waiting one is replaced, so there is one card, not two, and no second notice.
        $this->actingAsUser($agent);
        $second = $this->postJson('/api/v1/check-ins', $far)->assertCreated()->assertJsonPath('status', 'pending_review')->json('id');
        $this->assertSame(CheckIn::REPLACED, CheckIn::find($first)->status);
        $this->assertSame(1, Notice::where('user_id', $ws->id)->count());
        $this->actingAsUser($ws);
        $this->getJson('/api/v1/check-ins')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
        $this->postJson("/api/v1/check-ins/{$first}/review", ['decision' => 'approve'])->assertUnprocessable();

        // Now inside the area: verified straight away, the card and the alert clear.
        $this->actingAsUser($agent);
        $this->postJson('/api/v1/check-ins', ['latitude' => $u->latitude, 'longitude' => $u->longitude, 'accuracy_m' => 6, 'captured_at' => now()->toIso8601String()])
            ->assertCreated()->assertJsonPath('status', 'verified');
        $this->actingAsUser($ws);
        $this->getJson('/api/v1/check-ins')->assertJsonCount(0, 'data');
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->getJson('/api/v1/agents')->assertJsonPath('data.0.status', 'present');
    }

    public function test_reviewing_closes_the_alert(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        $u = $this->units['PU1'];
        $id = $this->postJson('/api/v1/check-ins', ['latitude' => $u->latitude + 0.0037, 'longitude' => $u->longitude, 'accuracy_m' => 10, 'captured_at' => now()->toIso8601String(), 'request_review' => true])->json('id');

        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $agent->ward_id, 'lga_id' => $this->lgas['L1']->id]));
        $this->postJson("/api/v1/check-ins/{$id}/review", ['decision' => 'reject'])->assertOk();
        $this->assertSame('resolved', Alert::where('type', 'checkin_review')->value('status'));
        $this->postJson("/api/v1/check-ins/{$id}/review", ['decision' => 'approve'])->assertUnprocessable();
    }
}
