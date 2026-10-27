<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A fresh production install: the first campaign and DG, and running the queue from cron. */
class InstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_campaign_and_dg_are_created_from_the_command_line(): void
    {
        $this->artisan('pollproof:create-campaign', [
            '--name' => 'Niger 2027', '--state' => 'Niger', '--election-date' => '2027-02-06',
            '--dg-name' => 'Amina Bello', '--dg-email' => 'dg@campaign.ng',
        ])->expectsOutputToContain('Temporary password')->assertSuccessful();

        $dg = User::where('email', 'dg@campaign.ng')->first();
        $this->assertSame('dg', $dg->role->value);
        $this->assertTrue($dg->must_change_password);
        $this->assertSame('Niger 2027', Campaign::find($dg->campaign_id)->name);
        $this->assertSame(count(NotificationRule::DEFAULTS), NotificationRule::where('campaign_id', $dg->campaign_id)->count());

        $this->artisan('pollproof:create-campaign', ['--name' => 'x', '--state' => 'x', '--election-date' => '2027-02-06', '--dg-name' => 'x', '--dg-email' => 'dg@campaign.ng'])
            ->assertFailed();
    }

    public function test_the_scheduler_runs_a_short_queue_worker_only_when_asked(): void
    {
        $commands = fn () => collect(app(Schedule::class)->events())->map->command->implode("\n");
        $this->assertStringNotContainsString('queue:work', $commands());

        config(['pollproof.queue_via_scheduler' => true]);
        require base_path('routes/console.php');
        $this->assertStringContainsString('queue:work --stop-when-empty --max-time=50', $commands());
        $this->assertStringContainsString('pollproof:watch', $commands());
    }
}
