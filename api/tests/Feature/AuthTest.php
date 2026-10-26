<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Services\Sms\LogSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        LogSmsSender::$sent = [];
    }

    public function test_agent_signs_in_with_sms_code(): void
    {
        $agent = $this->agentFor('PU1');
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $agent->phone])->assertOk();

        $this->assertCount(1, LogSmsSender::$sent);
        preg_match('/code is (\d{6})/', LogSmsSender::$sent[0]['message'], $m);

        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => $agent->phone, 'code' => $m[1]])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('polling_unit.code', 'PU1')
            ->assertJsonCount(3, 'parties');
    }

    public function test_wrong_code_is_rejected_and_unknown_numbers_get_the_same_answer(): void
    {
        $agent = $this->agentFor('PU1');
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $agent->phone])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => $agent->phone, 'code' => '000000'])->assertUnprocessable();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '08099999999'])
            ->assertOk()
            ->assertJsonPath('message', 'If this number is registered, a code has been sent.');
        $this->assertCount(1, LogSmsSender::$sent);
    }

    public function test_staff_sign_in_with_password_and_field_roles_cannot(): void
    {
        $dg = $this->staff(Role::Dg, ['email' => 'dg@test.ng']);
        $this->postJson('/api/v1/auth/login', ['email' => 'dg@test.ng', 'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'dg');
        $this->postJson('/api/v1/auth/login', ['email' => 'dg@test.ng', 'password' => 'wrong'])->assertUnprocessable();
    }

    public function test_roles_are_enforced(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $this->getJson('/api/v1/tally')->assertForbidden();
        $this->getJson('/api/v1/stipends')->assertForbidden();

        $this->actingAsUser($this->staff(Role::Finance));
        $this->getJson('/api/v1/stipends')->assertOk();
        $this->getJson('/api/v1/units/'.$this->units['PU1']->id.'/evidence')->assertForbidden();
    }
}
