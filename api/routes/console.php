<?php

use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\StructureImporter;
use Illuminate\Support\Facades\Artisan;

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
