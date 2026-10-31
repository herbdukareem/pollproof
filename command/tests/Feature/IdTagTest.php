<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Adding an agent, their photo, printing ID tags and checking a tag by its QR code. */
class IdTagTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('pollproof.evidence_disk'));
        $this->buildElection();
        $this->actingAsUser($this->staff(Role::SituationRoom));
    }

    public function test_add_one_agent_at_a_unit_with_a_photo(): void
    {
        $old = $this->agentFor('PU1');
        $this->post('/api/v1/roster/agents', [
            'name' => 'Zainab Musa', 'phone' => '0803 777 1234', 'polling_unit_id' => $this->units['PU1']->id,
            'photo' => UploadedFile::fake()->image('face.jpg', 300, 400),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('post', 'Agent at PU1')->assertJsonPath('replaced.code', $old->code);

        $agent = User::where('phone', '08037771234')->sole();
        $this->assertSame(Role::Agent, $agent->role);
        $this->assertNotNull($agent->photo_path);
        $this->assertTrue($old->fresh()->is_reserve);

        // As a reserve in a ward; and a phone that's taken is refused with who has it.
        $this->postJson('/api/v1/roster/agents', ['name' => 'Reserve One', 'phone' => '08037770000', 'ward_id' => $this->wards['L1']->id])->assertCreated()->assertJsonPath('post', fn ($p) => str_starts_with($p, 'Reserve agent'));
        $this->postJson('/api/v1/roster/agents', ['name' => 'Dup', 'phone' => '08037771234', 'ward_id' => $this->wards['L1']->id])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Zainab Musa'));
    }

    public function test_a_printed_tag_verifies_publicly_until_it_is_reissued_or_the_person_is_deactivated(): void
    {
        $agent = $this->agentFor('PU1', ['name' => 'Ibrahim Sule']);
        $this->post("/api/v1/people/{$agent->id}/photo", ['photo' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])->assertOk();

        $tag = $this->getJson('/api/v1/id-tags?ids[]='.$agent->id)->assertOk()->json('data.0');
        $this->assertSame(['Ibrahim Sule', 'PU1'], [$tag['name'], $tag['unit']['code']]);
        $token = basename($tag['verify_url']);
        $this->assertStringContainsString('/tag/', $tag['verify_url']);

        // Anyone can scan it: no sign-in.
        auth()->forgetGuards();
        $res = $this->getJson("/api/v1/id-tags/{$token}")->assertOk()->assertJsonPath('valid', true)->assertJsonPath('name', 'Ibrahim Sule')->assertJsonPath('role_label', 'Polling Unit Agent');
        $this->get($res->json('photo_url'))->assertOk();
        $this->assertArrayNotHasKey('phone', $res->json());

        $agent->update(['deactivated_at' => now()]);
        $this->getJson("/api/v1/id-tags/{$token}")->assertOk()->assertJsonPath('valid', false)->assertJsonPath('reason', fn ($r) => str_contains($r, 'deactivated'));

        $agent->update(['deactivated_at' => null]);
        $this->actingAsUser($this->staff(Role::Dg));
        $this->postJson("/api/v1/people/{$agent->id}/reissue-tag")->assertOk();
        auth()->forgetGuards();
        $this->getJson("/api/v1/id-tags/{$token}")->assertNotFound()->assertJsonPath('found', false);
    }

    public function test_tags_print_for_an_lga_and_supervisors(): void
    {
        $this->agentFor('PU1');
        User::factory()->role(Role::WardSupervisor)->create(['campaign_id' => $this->campaign->id, 'ward_id' => $this->wards['L1']->id, 'lga_id' => $this->wards['L1']->lga_id, 'code' => 'WS-001']);
        $this->getJson('/api/v1/id-tags?lga_id='.$this->wards['L1']->lga_id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/id-tags?ids='.User::where('code', 'WS-001')->value('id').',999999')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/id-tags?role=supervisors')->assertOk()->assertJsonPath('data.0.role_label', 'Ward Supervisor');
    }
}
