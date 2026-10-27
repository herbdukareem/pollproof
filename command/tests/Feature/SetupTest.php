<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendSms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    private function csv(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_agents_are_imported_and_bad_rows_are_reported(): void
    {
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $held = $this->agentFor('PU2');
        $held->update(['code' => 'AG-0040']);

        $file = $this->csv('agents.csv', "\xEF\xBB\xBFName,Phone,PU_Code,LGA_Code,Ward_Code,Reserve\n"
            ."Ada Obi,+234 803 111 2222,PU1,,,\n"
            ."Bala Musa,08031112222,PU3,,,\n"              // same phone as line 2
            ."Chidi Eze,0803 111 3333,PU2,,,\n"            // PU2 already has AG-0040
            ."Dayo Ade,08031114444,PU99,,,\n"              // unknown unit
            ."Efe Uche,12345,PU4,,,\n"                     // bad phone
            ."Fatima Sani,08031115555,,L1,W1,yes\n"        // reserve in a ward
            ."Gbenga Ola,08031116666,,L2,W1,\n");          // registered in a ward, no unit yet

        $this->post('/api/v1/roster/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['rows' => 7, 'assigned' => 1, 'reserves' => 1, 'unassigned' => 1, 'created' => 3])
            ->assertJsonCount(4, 'rejected')
            ->assertJsonPath('rejected.0.reason', 'Duplicate phone (also on line 2)')
            ->assertJsonPath('rejected.1.reason', 'PU2 already has agent AG-0040')
            ->assertJsonPath('rejected.2.reason', 'Unknown polling unit PU99')
            ->assertJsonPath('rejected.3.reason', 'Not a valid Nigerian mobile number');

        $ada = User::where('phone', '08031112222')->first();
        $this->assertSame($this->units['PU1']->id, $ada->polling_unit_id);
        $this->assertSame('AG-0041', $ada->code, 'codes continue after the highest existing one');
        $this->assertTrue(User::where('phone', '08031115555')->first()->is_reserve);
        $this->assertSame($this->wards['L2']->id, User::where('phone', '08031116666')->first()->ward_id);
        $this->assertSame($held->id, User::where('polling_unit_id', $this->units['PU2']->id)->value('id'));

        // Re-uploading a corrected row updates the same person.
        $this->post('/api/v1/roster/import', ['file' => $this->csv('fix.csv', "name,phone,pu_code\nAda Obi-Okafor,08031112222,PU5\n")], ['Accept' => 'application/json'])
            ->assertJson(['updated' => 1, 'created' => 0]);
        $this->assertSame($this->units['PU5']->id, $ada->fresh()->polling_unit_id);
        $this->assertSame('Ada Obi-Okafor', $ada->fresh()->name);
    }

    public function test_the_freeze_date_locks_structure_and_roster(): void
    {
        $this->campaign->update(['freeze_at' => now()->subDay()->toDateString()]);
        $this->actingAsUser($this->staff(Role::Dg));

        $this->post('/api/v1/roster/import', ['file' => $this->csv('a.csv', "name,phone\nA,08031110000\n")], ['Accept' => 'application/json'])->assertStatus(423);
        $this->patchJson("/api/v1/polling-units/{$this->units['PU1']->id}", ['latitude' => 9.6, 'longitude' => 6.5])->assertStatus(423);
        $this->postJson('/api/v1/roster/auto-assign', ['apply' => false])->assertOk(); // a preview is still fine
        $this->postJson('/api/v1/roster/auto-assign', ['apply' => true])->assertStatus(423);

        // The DG can move the date.
        $this->patchJson('/api/v1/campaign', ['freeze_at' => now()->addWeek()->toDateString()])->assertOk()->assertJsonPath('frozen', false);
        $this->patchJson("/api/v1/polling-units/{$this->units['PU1']->id}", ['latitude' => 9.6, 'longitude' => 6.5])->assertOk();
    }

    public function test_a_ward_supervisor_pins_gps_only_in_their_ward_and_only_with_a_good_fix(): void
    {
        $this->units['PU1']->update(['latitude' => null, 'longitude' => null]);
        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id, 'lga_id' => $this->lgas['L1']->id]));

        $this->patchJson("/api/v1/polling-units/{$this->units['PU1']->id}", ['latitude' => 9.61, 'longitude' => 6.51, 'accuracy_m' => 80])
            ->assertStatus(422)->assertJsonPath('message', 'GPS accuracy is 80 m; wait for 30 m or better before saving the pin.');
        $this->patchJson("/api/v1/polling-units/{$this->units['PU1']->id}", ['latitude' => 9.61, 'longitude' => 6.51, 'accuracy_m' => 6])->assertOk();
        $this->patchJson("/api/v1/polling-units/{$this->units['PU3']->id}", ['latitude' => 9.71, 'longitude' => 6.51])->assertForbidden();
        $this->patchJson("/api/v1/polling-units/{$this->units['PU1']->id}", ['latitude' => 51.5, 'longitude' => -0.1])->assertUnprocessable();
        $this->assertEquals(9.61, $this->units['PU1']->fresh()->latitude);
    }

    public function test_structure_view_lists_units_without_gps(): void
    {
        $this->units['PU3']->update(['latitude' => null]);
        $this->actingAsUser($this->staff(Role::Dg));

        $this->getJson("/api/v1/setup/structure?ward_id={$this->wards['L2']->id}")->assertOk()
            ->assertJsonPath('totals', ['lgas' => 3, 'wards' => 3, 'units' => 6, 'missing_gps' => 1])
            ->assertJsonPath('missing.0.code', 'PU3')
            ->assertJsonPath('lgas.1.missing_gps', 1)
            ->assertJsonCount(2, 'units');
    }

    public function test_supervisors_are_imported_and_open_wards_show_up(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $file = $this->csv('sup.csv', "name,phone,level,lga_code,ward_code,backup\n"
            ."Lead One,08032220001,lga,L1,,\n"
            ."Backup One,08032220002,lga,L1,,yes\n"
            ."Ward One,08032220003,ward,L1,W1,\n"
            ."Ward Two,08032220004,ward,L2,W1,\n"
            ."Nobody,08032220005,state,L1,,\n");
        $this->post('/api/v1/supervisors/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['lga' => 2, 'ward' => 2, 'replaced' => 0])
            ->assertJsonPath('rejected.0.reason', 'Level must be "lga" or "ward"');

        $this->getJson('/api/v1/setup/supervisors')->assertOk()
            ->assertJsonPath('levels.ward_supervisors', 2)
            ->assertJsonPath('levels.wards', 3)
            ->assertJsonPath('lgas.0.name', 'LGA L2') // gaps first: L2 has no LGA supervisor
            ->assertJsonPath('selected.wards.0.supervisor', null); // first LGA with an open ward is L3

        // Filling a post that is taken replaces the holder, who stays registered.
        $this->postJson('/api/v1/supervisors', ['name' => 'New Ward One', 'phone' => '0803 222 0009', 'level' => 'ward', 'ward_id' => $this->wards['L1']->id])
            ->assertCreated()->assertJsonPath('replaced', 'WS-001');
        $this->assertNull(User::where('phone', '08032220003')->value('ward_id'));
        $this->assertSame(Role::WardSupervisor, User::where('phone', '08032220009')->first()->role);
    }

    public function test_invites_are_queued_for_agents_without_the_app(): void
    {
        Queue::fake();
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $withApp = $this->agentFor('PU1');
        $this->deviceFor($withApp);
        $without = $this->agentFor('PU2');

        $this->postJson('/api/v1/roster/invite')->assertOk()->assertJson(['sent' => 1]);
        Queue::assertPushed(SendSms::class, fn ($job) => $job->phone === $without->phone && str_contains($job->message, 'PU2') && str_contains($job->message, 'AG-PU2'));
        Queue::assertPushed(SendSms::class, 1);
        $this->assertNotNull($without->fresh()->invited_at);
    }

    public function test_auto_assign_previews_then_fills_empty_units_in_the_agents_ward(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $this->agentFor('PU1');
        $waiting = User::factory()->role(Role::Agent)->create(['campaign_id' => $this->campaign->id, 'ward_id' => $this->wards['L1']->id, 'code' => 'AG-W1']);
        $reserve = User::factory()->role(Role::Agent)->create(['campaign_id' => $this->campaign->id, 'ward_id' => $this->wards['L1']->id, 'code' => 'AG-R1', 'is_reserve' => true]);

        $this->postJson('/api/v1/roster/auto-assign', ['apply' => false])->assertOk()
            ->assertJson(['count' => 1, 'applied' => false])->assertJsonPath('pairs.0.unit', 'PU2');
        $this->assertNull($waiting->fresh()->polling_unit_id);

        $this->postJson('/api/v1/roster/auto-assign', ['apply' => true])->assertOk()->assertJson(['count' => 1]);
        $this->assertSame($this->units['PU2']->id, $waiting->fresh()->polling_unit_id);
        $this->assertNull($reserve->fresh()->polling_unit_id, 'reserves are kept back');
    }

    public function test_roster_and_readiness_follow_each_agent_through_onboarding(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $a1 = $this->agentFor('PU1', ['invited_at' => now()]);
        $this->deviceFor($a1);
        $a1->update(['practice_passed_at' => now(), 'trained_at' => now(), 'confirmed_at' => now()]);
        $a2 = $this->agentFor('PU2', ['invited_at' => now()]);
        $this->deviceFor($a2);
        $a2->update(['practice_passed_at' => now()]);
        $this->agentFor('PU3');

        $this->getJson('/api/v1/readiness')->assertOk()
            ->assertJsonPath('funnel.0.value', 3)
            ->assertJsonPath('funnel.1.value', 2)
            ->assertJsonPath('funnel.2.value', 2)
            ->assertJsonPath('funnel.3.value', 1)
            ->assertJsonPath('funnel.4.value', 1)
            ->assertJsonPath('gaps.units_without_agent', 3)
            ->assertJsonPath('gaps.not_installed', 1)
            ->assertJsonPath('gaps.not_trained', 1)
            ->assertJsonPath('gaps.not_confirmed', 2);

        $this->getJson('/api/v1/setup/roster?filter=incomplete')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.code', 'AG-PU3')
            ->assertJsonPath('data.0.step_label', 'Not invited')
            ->assertJsonPath('data.1.step_label', 'Practice sent');

        Queue::fake();
        $this->postJson('/api/v1/readiness/remind', ['group' => 'not_confirmed'])->assertOk()->assertJson(['sent' => 2]);
        Queue::assertPushed(SendSms::class, fn ($job) => str_contains($job->message, 'Reply YES'));
    }
}
