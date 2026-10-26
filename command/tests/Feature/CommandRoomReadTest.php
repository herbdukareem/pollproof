<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Services\ResultSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class CommandRoomReadTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_structure_lists_lgas_wards_and_parties(): void
    {
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->getJson('/api/v1/structure')->assertOk()
            ->assertJsonCount(3, 'lgas')
            ->assertJsonPath('lgas.0.wards.0.units', 2)
            ->assertJsonCount(3, 'parties');
    }

    public function test_agent_roster_shows_status_and_hides_phones_from_roles_that_should_not_see_them(): void
    {
        $present = $this->agentFor('PU1');
        $this->agentFor('PU2');
        User::factory()->role(Role::Agent)->create(['campaign_id' => $this->campaign->id, 'is_reserve' => true, 'code' => 'AG-R1']);
        CheckIn::create(['campaign_id' => $this->campaign->id, 'user_id' => $present->id, 'polling_unit_id' => $present->polling_unit_id,
            'latitude' => 9.6, 'longitude' => 6.5, 'accuracy_m' => 5, 'status' => CheckIn::VERIFIED, 'captured_at' => now()]);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $res = $this->getJson('/api/v1/agents')->assertOk();
        $res->assertJsonPath('summary.present', 1)->assertJsonPath('summary.absent', 1)->assertJsonPath('summary.reserve', 1);
        $this->assertNotNull($res->json('data.0.phone'));

        $this->actingAsUser($this->staff(Role::Verifier));
        $this->getJson('/api/v1/agents')->assertForbidden();
    }

    public function test_supervisors_see_pending_check_ins_in_their_ward_only(): void
    {
        foreach (['PU1', 'PU3'] as $code) {
            $agent = $this->agentFor($code);
            CheckIn::create(['campaign_id' => $this->campaign->id, 'user_id' => $agent->id, 'polling_unit_id' => $agent->polling_unit_id,
                'latitude' => 9.6, 'longitude' => 6.5, 'accuracy_m' => 5, 'distance_m' => 410, 'status' => CheckIn::PENDING_REVIEW, 'captured_at' => now()]);
        }
        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id, 'lga_id' => $this->lgas['L1']->id]));

        $this->getJson('/api/v1/check-ins')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.polling_unit.code', 'PU1');
    }

    public function test_verifiers_can_view_the_ec8a_photo(): void
    {
        Storage::fake('local');
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);
        $photo = UploadedFile::fake()->image('ec8a.jpg', 400, 600);
        $sha = hash_file('sha256', $photo->getRealPath());
        $body = $this->signedResult($device, $sk, 'PU1', ['A' => 10], 20);
        // Re-sign with the photo fingerprint included.
        $canonical = ResultSigner::canonical('PU1', 20, 0, ['A' => 10], $sha, 9.6, 6.5, $body['captured_at'], null);
        $body['photo_sha256'] = $sha;
        $body['signature'] = base64_encode(sodium_crypto_sign_detached($canonical, $sk));
        $body['photo'] = $photo;

        $id = $this->post('/api/v1/results', $body, ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->assertTrue(ResultSubmission::find($id)->photo_path !== null);

        $this->actingAsUser($this->staff(Role::Verifier));
        $this->get("/api/v1/results/{$id}/photo")->assertOk();
    }
}
