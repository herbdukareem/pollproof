<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsFailed;
use App\Services\Sms\TermiiSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_the_dg_adds_a_user_who_must_change_the_temporary_password(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $res = $this->postJson('/api/v1/users', ['name' => 'Ngozi Verifier', 'email' => 'ngozi@campaign.ng', 'role' => 'verifier'])->assertCreated();
        $temp = $res->json('temporary_password');
        $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/', $temp);
        $this->postJson('/api/v1/users', ['name' => 'Agent', 'email' => 'a@campaign.ng', 'role' => 'agent'])->assertJsonValidationErrors('role');

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ngozi@campaign.ng', 'password' => $temp])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('must_change_password', true);

        $this->withToken($token)->postJson('/api/v1/me/password', ['current_password' => $temp, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
        $this->withToken($token)->postJson('/api/v1/me/password', ['current_password' => $temp, 'password' => 'collation2027', 'password_confirmation' => 'collation2027'])
            ->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('must_change_password', false);
    }

    public function test_a_deactivated_user_loses_access_at_once(): void
    {
        $dg = $this->staff(Role::Dg);
        $legal = $this->staff(Role::Legal, ['email' => 'legal@campaign.ng']);
        $token = $legal->createToken('t', ['legal'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->actingAsUser($dg);
        $this->patchJson("/api/v1/users/{$legal->id}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->patchJson("/api/v1/users/{$dg->id}", ['active' => false])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized(); // token revoked
        $this->postJson('/api/v1/auth/login', ['email' => 'legal@campaign.ng', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_only_the_dg_manages_users_and_the_campaign(): void
    {
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->patchJson('/api/v1/campaign', ['geofence_radius_m' => 500])->assertForbidden();
    }

    public function test_party_codes_are_validated_and_parties_with_votes_stay(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $this->postJson('/api/v1/parties', ['code' => 'A-1', 'name' => 'Bad'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/parties', ['code' => 'X', 'name' => 'Reserved'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/parties', ['code' => 'b', 'name' => 'Duplicate'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/parties', ['code' => 'lp', 'name' => 'Labour Party', 'is_ours' => true])->assertCreated()
            ->assertJsonPath('parties.3.code', 'LP')->assertJsonPath('parties.3.is_ours', true)->assertJsonPath('parties.0.is_ours', false);

        $lp = $this->campaign->parties()->where('code', 'LP')->first();
        $this->deleteJson("/api/v1/parties/{$lp->id}")->assertOk()->assertJsonCount(3, 'parties');

        // Once results are in, every party on the ballot is part of them.
        $this->submitResult('PU1', ['A' => 10, 'B' => 5, 'C' => 1], 20);
        $this->actingAsUser($this->staff(Role::Dg));
        $b = $this->campaign->parties()->where('code', 'B')->first();
        $this->deleteJson("/api/v1/parties/{$b->id}")->assertUnprocessable();
    }

    public function test_termii_receives_the_number_in_international_form(): void
    {
        Http::fake(['api.ng.termii.com/*' => Http::response(['code' => 'ok', 'message_id' => '1'])]);
        (new TermiiSmsSender('key', 'PollProof'))->send('0803 111 2222', 'Hello');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.ng.termii.com/api/sms/send'
            && $r['to'] === '2348031112222' && $r['from'] === 'PollProof' && $r['channel'] === 'dnd' && $r['sms'] === 'Hello');
    }

    public function test_a_termii_failure_is_reported(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Insufficient balance'], 400)]);
        $this->expectException(SmsFailed::class);
        $this->expectExceptionMessage('Insufficient balance');
        (new TermiiSmsSender('key', 'PollProof'))->send('08031112222', 'Hello');
    }

    public function test_otp_works_with_an_international_number(): void
    {
        $agent = $this->agentFor('PU1', ['phone' => '08031119999']);
        LogSmsSender::$sent = [];
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '+234 803 111 9999'])->assertOk();
        $this->assertSame('08031119999', LogSmsSender::$sent[0]['phone'] ?? null);
    }
}
