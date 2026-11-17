<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Election;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Each race's candidates: a party fields a different person, with their own photo, in each race. */
class CandidatePhotoTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pollproof.evidence_disk' => 'local']);
        $this->buildElection();
    }

    private function party(string $code): Party
    {
        return Party::where('campaign_id', $this->campaign->id)->where('code', $code)->firstOrFail();
    }

    private function upload(Election $race, Party $party): TestResponse
    {
        return $this->post("/api/v1/elections/{$race->id}/parties/{$party->id}/photo", ['photo' => UploadedFile::fake()->image('face.jpg', 300, 300)], ['Accept' => 'application/json']);
    }

    public function test_each_race_shows_its_own_candidate_and_photo(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        [$a, $b] = [$this->party('A'), $this->party('B')];
        $gov = Election::where('campaign_id', $this->campaign->id)->firstOrFail();
        $sen = Election::findOrFail($this->postJson('/api/v1/elections', [
            'type' => 'senate', 'name' => 'Senate (East)', 'code' => 'SEN-E', 'election_date' => '2027-02-06',
            'lga_ids' => [$this->lgas['L1']->id], 'parties' => [['id' => $a->id, 'candidate' => 'Amina Bello'], ['id' => $b->id, 'candidate' => 'Musa Garba']],
        ])->assertCreated()->json('id'));
        $this->patchJson("/api/v1/elections/{$gov->id}", [
            'type' => 'governorship', 'name' => $gov->name, 'code' => 'GOV', 'election_date' => '2027-02-06',
            'parties' => [['id' => $a->id, 'candidate' => 'Umar Bago'], ['id' => $b->id], ['id' => $this->party('C')->id]],
        ])->assertOk();

        $photo = $this->upload($gov, $a)->assertOk()->json('parties.0.photo');
        $this->assertStringStartsWith("/api/v1/elections/{$gov->id}/parties/{$a->id}/photo?v=", $photo);

        // The governorship shows its candidate and photo; the senate race its own, with no photo yet.
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson("/api/v1/tally?election={$gov->id}")->assertJsonPath('candidates.0.candidate', 'Umar Bago')->assertJsonPath('candidates.0.photo', $photo);
        $this->getJson("/api/v1/tally?election={$sen->id}")->assertJsonPath('candidates.0.candidate', 'Amina Bello')->assertJsonPath('candidates.0.photo', null);
        $this->get($photo)->assertOk();
        $this->upload($gov, $a)->assertForbidden();

        // A party not on a race's ballot has no candidate there.
        $this->actingAsUser($this->staff(Role::Dg));
        $this->upload($sen, $this->party('C'))->assertNotFound();
    }

    public function test_replacing_removing_or_dropping_from_the_ballot_deletes_the_old_photo(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $race = Election::where('campaign_id', $this->campaign->id)->firstOrFail();
        [$a, $b] = [$this->party('A'), $this->party('B')];
        $path = fn (Party $p) => $race->parties()->whereKey($p->id)->first()?->pivot->photo_path;

        $this->upload($race, $a)->assertOk();
        $first = $path($a);
        $this->upload($race, $a)->assertOk();
        Storage::disk('local')->assertMissing($first);

        $this->deleteJson("/api/v1/elections/{$race->id}/parties/{$a->id}/photo")->assertOk()->assertJsonPath('parties.0.photo', null);
        $this->assertNull($path($a));

        $this->upload($race, $b)->assertOk();
        $bPhoto = $path($b);
        $this->patchJson("/api/v1/elections/{$race->id}", [
            'type' => 'governorship', 'name' => $race->name, 'code' => 'GOV', 'election_date' => '2027-02-06',
            'parties' => [['id' => $a->id], ['id' => $this->party('C')->id]],
        ])->assertOk();
        Storage::disk('local')->assertMissing($bPhoto);

        $this->post("/api/v1/elections/{$race->id}/parties/{$a->id}/photo", ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
    }
}
