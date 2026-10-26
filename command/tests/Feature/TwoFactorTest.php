<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_totp_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('279037', Totp::code($secret, intdiv(2000000000, 30)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function test_staff_enrol_and_then_need_a_code_to_sign_in_on_the_web(): void
    {
        $legal = $this->staff(Role::Legal, ['email' => 'legal@campaign.ng']);
        $this->actingAs($legal);
        $this->postJson('/api/v1/me/two-factor', ['password' => 'wrong'])->assertJsonValidationErrors('password');
        $secret = $this->postJson('/api/v1/me/two-factor', ['password' => 'password'])->assertOk()
            ->assertJsonPath('uri', fn ($u) => str_starts_with($u, 'otpauth://totp/PollProof%3Alegal%40campaign.ng?secret='))->json('secret');
        $this->postJson('/api/v1/me/two-factor/confirm', ['code' => '000000'])->assertJsonValidationErrors('code');
        $codes = $this->postJson('/api/v1/me/two-factor/confirm', ['code' => Totp::code($secret, Totp::currentStep())])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotSame($secret, $legal->fresh()->getRawOriginal('two_factor_secret'), 'stored encrypted');

        $this->app['auth']->forgetGuards();
        $this->travel(1)->minutes(); // the enrolment code was used; move on to a fresh step
        $spa = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];
        $this->withHeaders($spa)->postJson('/session', ['email' => 'legal@campaign.ng', 'password' => 'password'])->assertOk()->assertJson(['two_factor' => true]);
        $this->assertGuest('web');
        $this->withHeaders($spa)->postJson('/session/two-factor', ['code' => '123456'])->assertJsonValidationErrors('code');
        $code = Totp::code($secret, Totp::currentStep());
        $this->withHeaders($spa)->postJson('/session/two-factor', ['code' => $code])->assertOk()->assertJsonPath('user.role', 'legal');
        $this->assertAuthenticatedAs($legal->fresh(), 'web');

        // The same code can't be used again, but a recovery code works once.
        $this->withHeaders($spa)->deleteJson('/session');
        $this->withHeaders($spa)->postJson('/session', ['email' => 'legal@campaign.ng', 'password' => 'password']);
        $this->withHeaders($spa)->postJson('/session/two-factor', ['code' => $code])->assertJsonValidationErrors('code');
        $this->withHeaders($spa)->postJson('/session/two-factor', ['code' => strtoupper($codes[0])])->assertOk();
        $this->withHeaders($spa)->deleteJson('/session');
        $this->withHeaders($spa)->postJson('/session', ['email' => 'legal@campaign.ng', 'password' => 'password']);
        $this->withHeaders($spa)->postJson('/session/two-factor', ['code' => $codes[0]])->assertJsonValidationErrors('code');
    }

    public function test_token_sign_in_asks_for_the_code(): void
    {
        $user = $this->staff(Role::DataOfficer, ['email' => 'data@campaign.ng']);
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->postJson('/api/v1/auth/login', ['email' => 'data@campaign.ng', 'password' => 'password'])
            ->assertUnprocessable()->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/auth/login', ['email' => 'data@campaign.ng', 'password' => 'password', 'code' => Totp::code($secret, Totp::currentStep())])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_campaign_can_require_two_step_and_staff_must_set_it_up_first(): void
    {
        $this->campaign->update(['require_two_factor' => true]);
        $this->actingAsUser($room = $this->staff(Role::SituationRoom));

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('two_factor_required', true);
        $this->getJson('/api/v1/tally')->assertForbidden()->assertJsonPath('message', 'Set up two-step sign-in first.');
        $secret = $this->postJson('/api/v1/me/two-factor', ['password' => 'password'])->json('secret');
        $this->postJson('/api/v1/me/two-factor/confirm', ['code' => Totp::code($secret, Totp::currentStep())])->assertOk();
        $this->getJson('/api/v1/tally')->assertOk();
        $this->deleteJson('/api/v1/me/two-factor', ['password' => 'password'])->assertUnprocessable();

        // Lost phone: the DG resets it and the person sets it up again.
        $this->actingAsUser($this->staff(Role::Dg, ['two_factor_secret' => Totp::newSecret(), 'two_factor_confirmed_at' => now()]));
        $this->postJson("/api/v1/users/{$room->id}/reset-two-factor")->assertOk();
        $this->assertFalse($room->fresh()->hasTwoFactor());
    }

    public function test_temporary_passwords_are_enforced_by_the_api_too(): void
    {
        $this->actingAsUser($this->staff(Role::Verifier, ['must_change_password' => true]));
        $this->getJson('/api/v1/results-queue')->assertForbidden();
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_repeated_failed_sign_ins_alert_the_dg(): void
    {
        $this->staff(Role::Finance, ['email' => 'finance@campaign.ng']);
        foreach (range(1, 3) as $i) {
            $this->postJson('/session', ['email' => 'finance@campaign.ng', 'password' => "guess{$i}"])->assertUnprocessable();
        }
        $alert = Alert::where('type', 'failed_logins')->first();
        $this->assertNotNull($alert);
        $this->assertSame('high', $alert->severity);
        $this->assertStringContainsString('finance@campaign.ng', $alert->detail);
    }
}
