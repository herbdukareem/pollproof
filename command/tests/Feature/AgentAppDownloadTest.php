<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\AgentAppBuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** The agent app download in the command room. */
class AgentAppDownloadTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    private string $apk = "PK\x03\x04 fake apk bytes";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        config(['pollproof.agent_app.release_url' => 'https://releases.test/agent-app']);
        File::deleteDirectory(app(AgentAppBuild::class)->dir());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(AgentAppBuild::class)->dir());
        parent::tearDown();
    }

    private function fakeRelease(?string $sha = null): void
    {
        Http::fake([
            'releases.test/agent-app/version.json' => Http::response(['version' => '1.0.0', 'build' => 42, 'built_at' => '2026-10-31T09:00:00Z', 'size' => strlen($this->apk), 'sha256' => $sha ?? hash('sha256', $this->apk)]),
            'releases.test/agent-app/pollproof-agent.apk' => Http::response($this->apk),
        ]);
    }

    public function test_before_a_copy_is_on_the_server_the_download_goes_to_the_published_build(): void
    {
        $this->actingAsUser($this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id]));
        $this->getJson('/api/v1/agent-app')->assertOk()->assertJsonPath('build', null)->assertJsonPath('can_update', false);
        $this->get('/api/v1/agent-app/download')->assertRedirect('https://releases.test/agent-app/pollproof-agent.apk');
        $this->postJson('/api/v1/agent-app/update')->assertForbidden();
    }

    public function test_the_dg_gets_the_latest_build_and_staff_download_it_from_this_server(): void
    {
        $this->fakeRelease();
        $this->actingAsUser($this->staff(Role::Dg));
        $this->postJson('/api/v1/agent-app/update')->assertOk()->assertJsonPath('build.build', 42);
        $this->assertTrue(AuditLog::where('action', 'agent_app.updated')->exists());

        $info = $this->getJson('/api/v1/agent-app')->assertJsonPath('build.version', '1.0.0')->assertJsonPath('can_update', true)->json();
        $res = $this->get('/api/v1/agent-app/download')->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('pollproof-agent-1.0.0.apk', $res->headers->get('Content-Disposition'));

        // The QR link works without signing in, but not once tampered with or expired.
        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->get($info['phone_url'])->assertOk();
        $this->get($info['phone_url'].'x')->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($info['phone_url'])->assertForbidden();
    }

    public function test_a_download_that_does_not_match_the_checksum_is_refused(): void
    {
        $this->fakeRelease(str_repeat('0', 64));
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->postJson('/api/v1/agent-app/update')->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'does not match'));
        $this->assertNull(app(AgentAppBuild::class)->local());
    }

    public function test_agents_cannot_use_the_command_room_download(): void
    {
        $this->actingAsUser($this->agentFor('PU1'));
        $this->getJson('/api/v1/agent-app')->assertForbidden();
    }
}
