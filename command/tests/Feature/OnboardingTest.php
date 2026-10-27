<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Services\ResultSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        config(['pollproof.sms_webhook_token' => 'hook-secret']);
    }

    public function test_a_practice_result_is_signed_for_the_practice_unit_and_never_counts(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        [$device, $sk] = $this->deviceFor($agent);
        $body = ['device_id' => $device->id, 'captured_at' => '2027-01-20T14:00:00Z', 'accredited' => 100, 'rejected' => 2, 'votes' => ['A' => 50, 'B' => 48]];

        // Signed for the real unit: refused, so a practice can't be replayed as a result or vice versa.
        $real = ResultSigner::canonical('PU1', 100, 2, ['A' => 50, 'B' => 48], null, null, null, '2027-01-20T14:00:00Z', null);
        $this->postJson('/api/v1/onboarding/practice', $body + ['signature' => base64_encode(sodium_crypto_sign_detached($real, $sk))])
            ->assertUnprocessable()->assertJsonPath('passed', false);

        $practice = ResultSigner::canonical('PRACTICE', 100, 2, ['A' => 50, 'B' => 48], null, null, null, '2027-01-20T14:00:00Z', null);
        $this->postJson('/api/v1/onboarding/practice', $body + ['signature' => base64_encode(sodium_crypto_sign_detached($practice, $sk))])
            ->assertOk()->assertJsonPath('passed', true)->assertJsonPath('fingerprint', strtoupper(substr(hash('sha256', $practice), 0, 8)));

        $this->assertNotNull($agent->fresh()->practice_passed_at);
        $this->assertDatabaseCount('result_submissions', 0);
        $this->getJson('/api/v1/onboarding')->assertOk()->assertJsonPath('step', 3)->assertJsonPath('step_label', 'Practice sent');
    }

    public function test_the_quiz_is_marked_on_the_server(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        $quiz = $this->getJson('/api/v1/onboarding')->assertOk()->json('quiz');
        $this->assertCount(10, $quiz);
        $this->assertArrayNotHasKey('answer', $quiz[0]);

        $answers = collect(config('pollproof_quiz'))->mapWithKeys(fn ($q) => [$q['id'] => $q['answer']]);
        $wrong = $answers->map(fn () => 'z')->all();
        $this->postJson('/api/v1/onboarding/quiz', ['answers' => $wrong])->assertOk()->assertJson(['score' => 0, 'passed' => false]);
        $this->assertNull($agent->fresh()->trained_at);

        $sevenRight = $answers->map(fn ($a, $id) => in_array($id, ['q8', 'q9', 'q10'], true) ? 'z' : $a)->all();
        $this->postJson('/api/v1/onboarding/quiz', ['answers' => $sevenRight])->assertJson(['score' => 7, 'passed' => false])
            ->assertJsonPath('questions.7.correct', false)->assertJsonPath('questions.7.answer', 'b');

        $this->postJson('/api/v1/onboarding/quiz', ['answers' => $answers->all()])->assertJson(['score' => 10, 'passed' => true]);
        $this->assertNotNull($agent->fresh()->trained_at);
        $this->assertSame(10, $agent->fresh()->quiz_score);
    }

    public function test_an_agent_who_cannot_make_it_raises_an_alert_and_a_yes_clears_it(): void
    {
        $agent = $this->actingAsUser($this->agentFor('PU1'));
        $this->postJson('/api/v1/onboarding/confirm', ['available' => false, 'note' => 'My mother is unwell'])->assertOk();

        $alert = Alert::where('type', 'agent_unavailable')->first();
        $this->assertSame('open', $alert->status);
        $this->assertSame('PU1: agent can\'t make election day', $alert->title);
        $this->assertStringContainsString('My mother is unwell', $alert->detail);

        // Later the agent replies YES to the confirmation SMS.
        $this->withHeaders(['X-Webhook-Token' => 'hook-secret'])
            ->postJson('/api/v1/sms/inbound', ['from' => $agent->phone, 'text' => 'Yes'])
            ->assertOk()->assertJsonPath('reply', 'Thank you. You are confirmed for PU1. Be there by 7:30 am.');
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertNotNull($agent->fresh()->confirmed_at);
        $this->assertNull($agent->fresh()->unavailable_at);
    }

    public function test_a_no_by_sms_is_recorded(): void
    {
        $agent = $this->agentFor('PU2');
        $this->withHeaders(['X-Webhook-Token' => 'hook-secret'])
            ->postJson('/api/v1/sms/inbound', ['from' => $agent->phone, 'text' => 'NO travelling'])
            ->assertOk()->assertJsonPath('reply', 'Thank you for telling us. Your supervisor will arrange cover.');
        $this->assertNotNull($agent->fresh()->unavailable_at);
        $this->assertStringContainsString('travelling', Alert::where('type', 'agent_unavailable')->value('detail'));
    }

    public function test_a_selfie_is_required_when_the_campaign_asks_for_one(): void
    {
        $this->campaign->update(['require_selfie' => true]);
        $this->actingAsUser($this->agentFor('PU1'));
        $unit = $this->units['PU1'];
        $this->postJson('/api/v1/check-ins', ['latitude' => $unit->latitude, 'longitude' => $unit->longitude, 'accuracy_m' => 5, 'captured_at' => now()->toIso8601String()])
            ->assertJsonValidationErrors('selfie');
    }
}
