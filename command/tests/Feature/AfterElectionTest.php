<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ResultSubmission;
use App\Models\TribunalCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;
use ZipArchive;

class AfterElectionTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        Storage::fake('local');
    }

    public function test_the_declaration_is_compared_with_our_records_and_locks_election_day_data(): void
    {
        $this->submitResult('PU1', ['A' => 200, 'B' => 100, 'C' => 10], 320);
        $this->submitResult('PU3', ['A' => 150, 'B' => 120, 'C' => 5], 280);
        $agent = $this->agentFor('PU5');

        $this->actingAsUser($this->staff(Role::Legal));
        $this->postJson('/api/v1/closeout/declare', ['declared_at' => now()->toIso8601String(), 'votes' => ['A' => 350]])->assertForbidden();

        $this->actingAsUser($this->staff(Role::Dg));
        $this->postJson('/api/v1/closeout/declare', ['declared_at' => now()->subHour()->toIso8601String(), 'votes' => ['a' => 350, 'b' => 260, 'c' => 15]])
            ->assertOk()
            ->assertJsonPath('declared', true)
            ->assertJsonPath('winner.party', 'A')
            ->assertJsonPath('winner.is_ours', true)
            ->assertJsonPath('winner.margin', 90)
            ->assertJsonPath('rows.1.party', 'B')
            ->assertJsonPath('rows.1.difference', 40)
            ->assertJsonPath('units_reported', 2);

        // Election-day writes are now refused.
        $this->actingAsUser($agent);
        $u = $this->units['PU5'];
        $this->postJson('/api/v1/check-ins', ['latitude' => $u->latitude, 'longitude' => $u->longitude, 'accuracy_m' => 5, 'captured_at' => now()->toIso8601String()])->assertStatus(423);
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson('/api/v1/irev-entries', ['polling_unit_id' => $u->id, 'not_on_irev' => true, 'seen_at' => now()->toIso8601String()])->assertStatus(423);
    }

    public function test_deleting_the_data_is_blocked_while_a_case_is_open(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        TribunalCase::create(['campaign_id' => $this->campaign->id, 'title' => 'Party B v INEC', 'petitioner' => 'Party B']);
        $this->postJson('/api/v1/closeout/retention', ['choice' => 'delete_now'])->assertUnprocessable();
        $this->postJson('/api/v1/closeout/retention', ['choice' => 'keep_until_cases_end'])->assertOk()->assertJsonPath('retention.choice', 'keep_until_cases_end');
    }

    public function test_legal_builds_a_case_and_exports_a_verifiable_reply_bundle(): void
    {
        $resultId = $this->submitResult('PU1', ['A' => 200, 'B' => 100], 320);
        $photo = UploadedFile::fake()->image('ec8a.jpg', 600, 800);
        $sha = hash_file('sha256', $photo->getRealPath());
        ResultSubmission::find($resultId)->update(['photo_path' => $photo->store('ec8a', 'local'), 'photo_sha256' => $sha]);

        $legal = $this->actingAsUser($this->staff(Role::Legal, ['name' => 'Legal Lead']));
        $case = $this->postJson('/api/v1/tribunal-cases', [
            'title' => 'Party B v INEC and others', 'petitioner' => 'Party B',
            'milestones' => [['date' => '27 Feb 2027', 'label' => 'Petition filed'], ['date' => '3 Mar 2027', 'label' => 'Served on us']],
        ])->assertCreated()->json('case.id');

        $this->postJson("/api/v1/tribunal-cases/{$case}/grounds", ['label' => 'OVER-VOTING', 'claim' => 'Votes exceeded accreditation', 'unit_codes' => ['pu99']])
            ->assertUnprocessable();
        $groundId = $this->postJson("/api/v1/tribunal-cases/{$case}/grounds", [
            'label' => 'OVER-VOTING', 'claim' => 'Votes exceeded accreditation in PU1', 'unit_codes' => ['pu1'], 'owner_id' => $legal->id,
        ])->assertCreated()->assertJsonPath('grounds.0.units.0.code', 'PU1')->json('grounds.0.id');
        $this->patchJson("/api/v1/tribunal-grounds/{$groundId}", ['answer' => 'Signed EC8A shows 300 votes cast against 320 accredited.', 'status' => 'ready'])
            ->assertOk()->assertJsonPath('grounds.0.status', 'ready');
        $taskId = $this->postJson("/api/v1/tribunal-cases/{$case}/tasks", ['title' => 'Export audit log', 'due_on' => '2027-03-09'])->json('tasks.0.id');
        $this->patchJson("/api/v1/tribunal-tasks/{$taskId}", ['done' => true])->assertJsonPath('tasks.0.done_at', fn ($v) => $v !== null);

        $res = $this->get("/api/v1/tribunal-cases/{$case}/bundle")->assertOk();
        $file = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
        $this->assertTrue($names->contains('grounds.md'));
        $this->assertTrue($names->contains('units/pu1/evidence.json'));
        $this->assertTrue($names->contains("units/pu1/ec8a-result-{$resultId}.jpg"));
        $this->assertTrue($names->contains('audit-log.csv'));
        $this->assertStringContainsString('Signed EC8A shows 300 votes cast', $zip->getFromName('grounds.md'));
        $this->assertSame($sha, hash('sha256', $zip->getFromName("units/pu1/ec8a-result-{$resultId}.jpg")), 'the photo is the one the agent signed');

        // Every file in the manifest checks out.
        foreach (array_filter(explode("\n", $zip->getFromName('MANIFEST.sha256'))) as $line) {
            [$hash, $name] = explode('  ', $line, 2);
            $this->assertSame($hash, hash('sha256', $zip->getFromName($name)), $name);
        }
        $zip->close();

        // Other roles can't see the workspace.
        $this->actingAsUser($this->staff(Role::Finance));
        $this->getJson("/api/v1/tribunal-cases/{$case}")->assertForbidden();
    }
}
