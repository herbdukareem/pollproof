<?php

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ElectionWatch;
use App\Services\StructureImporter;
use Illuminate\Support\Facades\Artisan;
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
Schedule::command('pollproof:watch')->everyMinute()->withoutOverlapping();

// Shared hosting can't keep a queue worker running, so the scheduler starts one each minute
// that stops when the queue is empty (or after 50 seconds, before the next run starts).
if (config('pollproof.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5);
}
