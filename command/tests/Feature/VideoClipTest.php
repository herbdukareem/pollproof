<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Incident;
use App\Models\VideoClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Videos recorded on the phone, sent in pieces that resume after a dropped connection. */
class VideoClipTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pollproof.evidence_disk' => 'local']);
        $this->buildElection();
    }

    private function describe(string $bytes, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/videos', $overrides + [
            'client_id' => (string) Str::uuid(), 'kind' => 'result', 'mime' => 'video/mp4',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_s' => 42,
            'captured_at' => '2027-02-06T15:41:00Z', 'latitude' => 9.6, 'longitude' => 6.5, 'note' => 'Presiding officer reading the EC8A',
        ]);
    }

    private function piece(int $id, int $offset, string $bytes): TestResponse
    {
        return $this->call('POST', "/api/v1/videos/{$id}/chunk", [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_UPLOAD_OFFSET' => (string) $offset,
        ], $bytes);
    }

    public function test_a_video_arrives_in_pieces_resumes_where_it_stopped_and_reaches_the_command_room(): void
    {
        $bytes = random_bytes(5000);
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        $start = $this->describe($bytes, ['client_id' => $clientId = (string) Str::uuid()])->assertCreated()
            ->assertJson(['received' => 0, 'size' => 5000, 'complete' => false]);
        $id = $start->json('id');

        $this->piece($id, 0, substr($bytes, 0, 2000))->assertOk()->assertJson(['received' => 2000, 'complete' => false]);
        // The connection drops; the phone asks again and is told where to carry on from.
        $this->describe($bytes, ['client_id' => $clientId])->assertOk()->assertJson(['id' => $id, 'received' => 2000]);
        // A piece sent twice is refused with the right offset.
        $this->piece($id, 0, substr($bytes, 0, 2000))->assertConflict()->assertJson(['received' => 2000]);
        $this->piece($id, 2000, substr($bytes, 2000))->assertOk()->assertJson(['received' => 5000, 'complete' => true]);

        $clip = VideoClip::findOrFail($id);
        $this->assertSame([$this->units['PU1']->id, $this->race()->id, $this->wards['L1']->id], [$clip->polling_unit_id, $clip->election_id, $clip->ward_id]);
        Storage::disk('local')->assertExists($clip->path);
        $this->assertSame($bytes, Storage::disk('local')->get($clip->path));
        Storage::disk('local')->assertMissing($clip->partPath());

        // Someone else's phone can't add to it.
        $this->actingAsUser($this->agentFor('PU2'));
        $this->piece($id, 5000, 'x')->assertNotFound();

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/videos')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.kind_label', 'Result announcement')
            ->assertJsonPath('data.0.polling_unit.code', 'PU1')
            ->assertJsonPath('data.0.by.code', $agent->code)
            ->assertJsonPath('counts.kinds.result', 1);
        $this->get("/api/v1/videos/{$id}/file")->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->actingAsUser($this->staff(Role::Legal));
        $this->getJson("/api/v1/units/{$this->units['PU1']->id}/evidence")->assertOk()->assertJsonPath('videos.0.id', $id);
    }

    public function test_a_damaged_upload_starts_again_and_an_unfinished_one_stays_out_of_sight(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $id = $this->describe('the real video', ['sha256' => hash('sha256', 'something else')])->json('id');

        $this->piece($id, 0, 'the real video')->assertOk()->assertJson(['received' => 0, 'complete' => false]);
        $this->assertNull(VideoClip::findOrFail($id)->completed_at);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/videos')->assertJsonPath('total', 0);
        $this->get("/api/v1/videos/{$id}/file")->assertNotFound();
    }

    public function test_an_incident_video_is_linked_to_the_incident_and_supervisors_see_only_their_area(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU3'));
        $incident = $this->postJson('/api/v1/incidents', ['type' => 'disruption', 'severity' => 'high', 'description' => 'Thugs at the unit', 'occurred_at' => now()->toIso8601String()])
            ->assertCreated()->json('id');
        $id = $this->describe('clip', ['kind' => 'incident', 'incident_id' => $incident])->json('id');
        $this->piece($id, 0, 'clip')->assertJson(['complete' => true]);
        $this->assertSame($incident, VideoClip::findOrFail($id)->incident_id);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/incidents')->assertJsonPath('data.0.videos.0.id', $id);

        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id]));
        $this->getJson('/api/v1/videos')->assertJsonPath('total', 0);
        $this->get("/api/v1/videos/{$id}/file")->assertNotFound();
        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L2']->id]));
        $this->getJson('/api/v1/videos')->assertJsonPath('total', 1);
        $this->assertSame($agent->id, Incident::findOrFail($incident)->user_id);
    }
}
