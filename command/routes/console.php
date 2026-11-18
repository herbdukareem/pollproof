<?php

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Campaign;
use App\Models\CollationEntry;
use App\Models\Lga;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\Ward;
use App\Services\AgentAppBuild;
use App\Services\AuditLogger;
use App\Services\ElectionWatch;
use App\Services\StructureImporter;
use App\Services\VideoChecker;
use App\Services\Voice\Kokoro;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('pollproof:import-structure {campaign : Campaign ID} {file : Path to the polling-unit CSV}', function (StructureImporter $importer) {
    $campaign = Campaign::findOrFail($this->argument('campaign'));
    $summary = $importer->import($campaign, $this->argument('file'));
    $this->info("Imported {$summary['units']} polling units in {$summary['wards']} wards and {$summary['lgas']} LGAs.");
    if ($summary['missing_gps'] > 0) {
        $this->warn("{$summary['missing_gps']} units have no GPS point; check-in cannot be verified there until one is set.");
    }
})->purpose('Import or update a campaign\'s LGAs, wards and polling units from CSV');

Artisan::command('pollproof:collation-orphans {--delete : Remove them, after asking}', function (AuditLogger $audit) {
    // Collation sheets name their ward or LGA by id, without a foreign key, so deleting areas in the
    // database (rather than re-importing, which keeps them) leaves sheets pointing at nothing.
    $orphans = CollationEntry::with('election')->get()->filter(fn ($e) => match ($e->level) {
        CollationEntry::WARD => ! Ward::whereKey($e->scope_id)->exists(),
        CollationEntry::LGA => ! Lga::whereKey($e->scope_id)->exists(),
        default => false,
    })->values();
    if ($orphans->isEmpty()) {
        $this->info('Every collation sheet points at a ward or LGA that exists.');

        return;
    }
    $this->table(['Sheet', 'Campaign', 'Race', 'Level', 'Missing area id', 'Announced'], $orphans->map(fn ($e) => [
        $e->id, $e->campaign_id, $e->election?->name, $e->level, $e->scope_id, $e->announced_at?->toDateTimeString(),
    ])->all());
    if (! $this->option('delete')) {
        $this->line("{$orphans->count()} sheet(s) point at a ward or LGA no longer in the structure. Run again with --delete to remove them.");

        return;
    }
    if (! $this->confirm("Delete these {$orphans->count()} collation sheet(s)? Their figures are kept in the audit log.")) {
        return;
    }
    foreach ($orphans as $e) {
        $audit->log(null, 'collation.orphan_removed', $e, ['level' => $e->level, 'scope_id' => $e->scope_id, 'votes' => $e->votes, 'announced_at' => (string) $e->announced_at], null, $e->campaign_id);
        $e->delete();
    }
    $this->info("Removed {$orphans->count()} sheet(s).");
})->purpose('List (or with --delete remove) collation sheets whose ward or LGA was deleted from the structure');

Artisan::command('pollproof:verify-audit {campaign : Campaign ID}', function (AuditLogger $audit) {
    $r = $audit->verify((int) $this->argument('campaign'));
    $r['valid']
        ? $this->info("Audit log intact: {$r['checked']} entries checked.")
        : $this->error("Audit chain broken at entry {$r['broken_at']} (after {$r['checked']} entries).");

    return $r['valid'] ? 0 : 1;
})->purpose('Check that the campaign audit log has not been altered');

Artisan::command('pollproof:watch', function (ElectionWatch $watch) {
    foreach (Campaign::all() as $campaign) {
        $r = $watch->run($campaign);
        $this->line("{$campaign->name}: escalated {$r['escalated']}, units without check-in {$r['absent']}, waiting for verification {$r['backlog']}");
        if ($r['race']) {
            $this->line("  Announced: {$r['race']}");
        }
    }
})->purpose('Escalate unanswered alerts and check election-day deadlines');

Artisan::command('pollproof:create-campaign {--name=} {--state=} {--election-date=} {--dg-name=} {--dg-email=}', function () {
    $name = $this->option('name') ?: $this->ask('Campaign name');
    $state = $this->option('state') ?: $this->ask('State');
    $date = $this->option('election-date') ?: $this->ask('Election date (YYYY-MM-DD)');
    $dgName = $this->option('dg-name') ?: $this->ask("DG's full name");
    $dgEmail = $this->option('dg-email') ?: $this->ask("DG's email");
    if (User::where('email', $dgEmail)->exists()) {
        $this->error("{$dgEmail} already has an account.");

        return 1;
    }
    $password = Str::password(14, symbols: false);
    $campaign = Campaign::create(['name' => $name, 'state_name' => $state, 'election_date' => $date]);
    User::create([
        'campaign_id' => $campaign->id, 'name' => $dgName, 'email' => $dgEmail, 'role' => Role::Dg,
        'password' => $password, 'must_change_password' => true,
    ]);
    $this->info("Campaign #{$campaign->id} created. The DG signs in at ".url('/login')." as {$dgEmail}");
    $this->line("Temporary password (shown once, must be changed at first sign-in): {$password}");
    $this->line('Next: the DG adds the parties under Campaign, then imports the polling-unit list under Structure.');

    return 0;
})->purpose('Create a campaign and its DG account on a fresh install');

// Run with `php artisan schedule:work`, or a cron job calling `schedule:run` every minute.
Artisan::command('pollproof:fetch-agent-app', function (AgentAppBuild $build) {
    $meta = $build->fetch();
    $this->info("Agent app {$meta['version']} (build {$meta['build']}) is on this server: ".$build->apkPath());
})->purpose('Copy the latest agent-app build from GitHub to this server');

Schedule::command('pollproof:watch')->everyMinute()->withoutOverlapping();
// When the scheduler last ran, so Ask PollProof can tell whether the cron job is working.
Schedule::call(fn () => Cache::forever('pollproof:scheduler-ran', now()->toIso8601String()))->everyMinute()->name('pollproof:heartbeat');

// Result announcement videos that arrived while AI was off (or before it was set up).
Artisan::command('pollproof:ai-check-videos', function (VideoChecker $checker) {
    $queued = VideoClip::where('kind', 'result')->whereNotNull('completed_at')->whereDoesntHave('check')->get()
        ->filter(fn (VideoClip $v) => $checker->queue($v))->count();
    $this->info(config('pollproof.ai.enabled') ? "{$queued} videos queued for checking." : 'AI is off: set OPENAI_API_KEY first.');
})->purpose('Check result announcement videos that have not been checked by the AI yet');
// Spoken announcements are only useful for minutes; keep a week of them, and three days of audio.
Schedule::call(function () {
    Announcement::where('created_at', '<', now()->subDays(7))->delete();
    app(Kokoro::class)->prune(3);
})->daily()->name('pollproof:voice-prune')->withoutOverlapping();
// A new agent-app build reaches this server within the hour of GitHub publishing it.
Schedule::command('pollproof:fetch-agent-app')->hourly()->withoutOverlapping();

// Shared hosting can't keep a queue worker running, so the scheduler starts one each minute
// that stops when the queue is empty (or after 50 seconds, before the next run starts).
if (config('pollproof.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5);
}
