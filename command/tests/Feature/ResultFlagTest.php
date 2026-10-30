<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class ResultFlagTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    private function signedResult(string $unit = 'PU1'): ResultSubmission
    {
        $agent = $this->agentFor($unit);

        return ResultSubmission::create([
            'campaign_id' => $this->campaign->id, 'polling_unit_id' => $agent->polling_unit_id, 'user_id' => $agent->id,
            'accredited' => 300, 'rejected' => 2, 'total_valid' => 250, 'total_cast' => 252, 'captured_at' => now(), 'payload_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_a_reviewer_flags_a_result_with_a_comment_and_legal_is_alerted(): void
    {
        $result = $this->signedResult();
        $this->actingAsUser($this->staff(Role::Verifier));

        $this->postJson("/api/v1/results/{$result->id}/flags", ['reason' => 'figures_differ'])->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/results/{$result->id}/flags", ['reason' => 'made_up', 'note' => 'Something odd'])->assertJsonValidationErrors('reason');

        $flag = $this->postJson("/api/v1/results/{$result->id}/flags", ['reason' => 'figures_differ', 'note' => 'Party B reads 126 on the photo but looks overwritten.'])
            ->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('severity', 'high')->json();

        $result->refresh();
        $this->assertSame(ResultSubmission::PENDING_REVIEW, $result->status, 'a flag does not change the result');
        $this->assertSame(252, $result->total_cast);
        $alert = Alert::where('dedupe_key', "result_flag:{$flag['id']}")->first();
        $this->assertSame('result_flagged', $alert->type);
        $this->assertSame('high', $alert->severity);
        $this->assertTrue(AuditLog::where('action', 'result.flagged')->exists());

        $this->getJson('/api/v1/results-queue?kind=review_flagged')->assertJsonPath('total', 1)->assertJsonPath('data.0.open_flags', 1)
            ->assertJsonPath('counts.review_flagged', 1);
        $this->getJson('/api/v1/units?status=review_flagged')->assertJsonCount(1, 'data')->assertJsonPath('data.0.open_flags', 1);
    }

    public function test_flags_are_discussed_cleared_with_a_note_and_reopened(): void
    {
        $result = $this->signedResult();
        $this->actingAsUser($this->staff(Role::Verifier));
        $id = $this->postJson("/api/v1/results/{$result->id}/flags", ['reason' => 'photo_unclear', 'note' => 'Bottom half of the sheet is cut off.'])->json('id');

        $this->actingAsUser($this->staff(Role::Legal));
        $this->postJson("/api/v1/result-flags/{$id}/comments", ['body' => 'Asked the ward supervisor to get a new photo.'])
            ->assertCreated()->assertJsonCount(1, 'comments')->assertJsonPath('comments.0.by.role', 'Legal Team');
        $this->patchJson("/api/v1/result-flags/{$id}", ['status' => 'cleared'])->assertJsonValidationErrors('note');
        $this->patchJson("/api/v1/result-flags/{$id}", ['status' => 'cleared', 'note' => 'New photo matches the figures.'])
            ->assertOk()->assertJsonPath('status', 'cleared')->assertJsonPath('clear_note', 'New photo matches the figures.');
        $this->assertSame('resolved', Alert::where('dedupe_key', "result_flag:{$id}")->value('status'));

        $this->patchJson("/api/v1/result-flags/{$id}", ['status' => 'open'])->assertOk()->assertJsonPath('status', 'open');
        $this->assertSame('open', Alert::where('dedupe_key', "result_flag:{$id}")->value('status'));

        $this->getJson("/api/v1/results/{$result->id}/flags")->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(6, 'reasons');
        $this->getJson("/api/v1/units/{$result->polling_unit_id}/evidence")->assertJsonPath('flags.0.id', $id);
    }

    public function test_only_command_room_reviewers_can_flag_and_never_across_campaigns(): void
    {
        $result = $this->signedResult();
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson("/api/v1/results/{$result->id}/flags", ['reason' => 'other', 'note' => 'Looks wrong to me'])->assertForbidden();

        $elsewhere = Campaign::create(['name' => 'Other', 'state_name' => 'Other State', 'election_date' => '2027-02-06']);
        $this->actingAsUser(User::factory()->role(Role::Verifier)->create(['campaign_id' => $elsewhere->id]));
        $this->postJson("/api/v1/results/{$result->id}/flags", ['reason' => 'other', 'note' => 'Looks wrong to me'])->assertNotFound();
        $this->assertSame(0, ResultFlag::count());
    }
}
