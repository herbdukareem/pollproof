<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\ResultSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class ResultSubmissionTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_a_signed_result_is_recorded_with_its_fingerprint(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);

        $res = $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 214, 'B' => 187, 'C' => 22], 448, 6))
            ->assertCreated()
            ->assertJsonPath('total_valid', 423)
            ->assertJsonPath('total_cast', 429)
            ->assertJsonPath('signature_valid', true)
            ->assertJsonPath('status', 'pending_review');

        $this->assertSame(8, strlen($res->json('fingerprint')));
        $this->assertSame([], $res->json('flags'));
    }

    public function test_a_tampered_or_badly_signed_result_is_refused(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);
        $body = $this->signedResult($device, $sk, 'PU1', ['A' => 214, 'B' => 187, 'C' => 22], 448);

        $tampered = $body;
        $tampered['votes']['B'] = 287;
        $this->postJson('/api/v1/results', $tampered)->assertUnprocessable()->assertJsonValidationErrors('signature');

        [, $otherKey] = $this->deviceFor($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $otherKey, 'PU1', ['A' => 1], 10))->assertUnprocessable();

        $this->assertSame(0, ResultSubmission::count());
    }

    public function test_agents_cannot_submit_for_another_unit_or_with_someone_elses_device(): void
    {
        $other = $this->agentFor('PU2');
        [$otherDevice, $otherKey] = $this->deviceFor($other);
        $this->actingAsUser($this->agentFor('PU1'));

        $this->postJson('/api/v1/results', $this->signedResult($otherDevice, $otherKey, 'PU1', ['A' => 1], 10))
            ->assertUnprocessable();
    }

    public function test_overvoting_is_flagged_and_raises_a_high_alert(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);

        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 214, 'B' => 287, 'C' => 22], 448, 6))
            ->assertCreated()->assertJsonPath('flags', ['overvote']);

        $alert = Alert::where('type', 'overvote')->sole();
        $this->assertSame('high', $alert->severity);
        $this->assertSame(529, $alert->data['total_cast']);
    }

    public function test_a_correction_keeps_the_original_and_becomes_current(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);
        $first = $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 214, 'B' => 287, 'C' => 22], 448))->json('id');

        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 214, 'B' => 187, 'C' => 22], 448, 0, $first))
            ->assertCreated()
            ->assertJsonPath('supersedes_id', $first)
            ->assertJsonPath('is_current', true);

        $this->assertFalse(ResultSubmission::find($first)->is_current);
        $this->assertSame(2, ResultSubmission::where('polling_unit_id', $this->units['PU1']->id)->count());
        $this->assertSame('resolved', Alert::where('type', 'overvote')->sole()->status);
    }

    public function test_a_second_result_must_be_sent_as_a_correction(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 10], 20))->assertCreated();

        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 11], 20))
            ->assertUnprocessable()->assertJsonValidationErrors('supersedes_id');
    }

    public function test_the_signed_app_copy_of_an_sms_result_links_to_it(): void
    {
        config(['pollproof.sms_webhook_token' => 'hook-secret']);
        $agent = $this->agentFor('PU1');
        $this->withHeader('X-Webhook-Token', 'hook-secret')
            ->postJson('/api/v1/sms/inbound', ['from' => $agent->phone, 'text' => 'R PU1 AC20 A10 B5 X1'])->assertOk();
        $smsId = ResultSubmission::sole()->id;

        $this->actingAsUser($agent);
        [$device, $sk] = $this->deviceFor($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 10, 'B' => 5], 20, 1))
            ->assertCreated()
            ->assertJsonPath('supersedes_id', $smsId)
            ->assertJsonPath('signature_valid', true);
    }

    public function test_a_second_person_reviews_results(): void
    {
        $id = $this->submitResult('PU1', ['A' => 10, 'B' => 5, 'C' => 1], 20);
        $verifier = $this->actingAsUser($this->staff(Role::Verifier));

        $this->getJson('/api/v1/results-queue')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/results/{$id}/send-back", [])->assertUnprocessable();
        $this->postJson("/api/v1/results/{$id}/send-back", ['note' => 'Signatures unclear, retake photo'])
            ->assertOk()->assertJsonPath('status', 'sent_back');
        $this->getJson('/api/v1/results-queue')->assertJsonCount(0, 'data');
    }
}
