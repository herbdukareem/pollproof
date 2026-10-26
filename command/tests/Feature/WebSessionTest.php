<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class WebSessionTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        $this->withoutVite();
    }

    public function test_every_web_path_serves_the_command_room_app(): void
    {
        $this->get('/')->assertOk()->assertSee('id="app"', false);
        $this->get('/units/42/evidence')->assertOk()->assertSee('id="app"', false);
        $this->getJson('/api/v1/tally')->assertUnauthorized();
    }

    public function test_staff_sign_in_with_a_cookie_and_reach_the_api(): void
    {
        $this->staff(Role::SituationRoom, ['email' => 'room@test.ng']);
        $spa = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

        $this->withHeaders($spa)->postJson('/session', ['email' => 'room@test.ng', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'situation_room');

        $this->withHeaders($spa)->getJson('/api/v1/tally')->assertOk()->assertJsonPath('units', 6);
        $this->withHeaders($spa)->deleteJson('/session')->assertOk();
    }

    public function test_whatever_host_serves_the_app_is_treated_as_its_own_frontend(): void
    {
        // Sanctum only reads the session cookie on API calls when the request comes from a stateful host.
        $request = Request::create('http://command.campaign.example/api/v1/me', server: ['HTTP_REFERER' => 'http://command.campaign.example/overview']);
        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));

        $other = Request::create('http://command.campaign.example/api/v1/me', server: ['HTTP_REFERER' => 'http://elsewhere.example/']);
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($other));
    }

    public function test_field_staff_cannot_use_the_web_sign_in(): void
    {
        $this->agentFor('PU1', ['email' => 'agent@test.ng', 'password' => 'password']);
        $this->postJson('/session', ['email' => 'agent@test.ng', 'password' => 'password'])->assertUnprocessable();
    }
}
