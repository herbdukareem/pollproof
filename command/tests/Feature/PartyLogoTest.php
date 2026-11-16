<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** A party's logo: the DG uploads it, the command room sees it on the scoreboard. */
class PartyLogoTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pollproof.evidence_disk' => 'local']);
        $this->buildElection();
    }

    public function test_the_dg_uploads_a_logo_that_the_tally_links_and_the_command_room_can_load(): void
    {
        $party = Party::where('code', 'A')->firstOrFail();
        $this->actingAsUser($this->staff(Role::Dg));

        $logo = $this->post("/api/v1/parties/{$party->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])->assertOk()
            ->json('parties.0.logo');
        $this->assertStringStartsWith("/api/v1/parties/{$party->id}/logo?v=", $logo);
        $first = $party->fresh()->logo_path;
        Storage::disk('local')->assertExists($first);

        // A new upload replaces the old file.
        $this->post("/api/v1/parties/{$party->id}/logo", ['logo' => UploadedFile::fake()->image('logo.jpg')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($first);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/tally')->assertJsonPath('candidates.0.logo', $party->fresh()->logo_url);
        $this->get($party->fresh()->logo_url)->assertOk();
        $this->post("/api/v1/parties/{$party->id}/logo", ['logo' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsUser($this->staff(Role::Dg));
        $this->deleteJson("/api/v1/parties/{$party->id}/logo")->assertOk()->assertJsonPath('parties.0.logo', null);
        $this->get("/api/v1/parties/{$party->id}/logo")->assertNotFound();
    }

    public function test_only_images_are_accepted(): void
    {
        $party = Party::where('code', 'B')->firstOrFail();
        $this->actingAsUser($this->staff(Role::Dg));
        $this->post("/api/v1/parties/{$party->id}/logo", ['logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
    }
}
