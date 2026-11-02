<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\AuditLogger;
use App\Services\InecLocator;
use App\Services\StructureImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Pull every polling unit of a state, with INEC's own GPS coordinates, from INEC's public
 * locator (https://cvr.inecnigeria.org/pu), and write it as a PollProof unit list, optionally
 * loading it straight into a campaign.
 *
 *   php artisan pollproof:inec-coordinates niger
 *   php artisan pollproof:inec-coordinates niger --campaign=1
 *   php artisan pollproof:inec-coordinates niger --concurrency=40 --fresh
 *
 * Wards for every LGA are fetched at once, then units for every ward at once, then coordinates
 * for every unit in concurrent batches (--concurrency at a time). Progress is saved after each
 * batch in storage/app/inec/<state>/, so an interrupted run carries on where it stopped.
 */
class InecCoordinates extends Command
{
    protected $signature = 'pollproof:inec-coordinates
        {state=niger : State name, or the INEC portal id (Niger is 27)}
        {--concurrency=20 : Requests to INEC in flight at once (1 to 100)}
        {--campaign= : Campaign ID to load the units and coordinates into}
        {--fresh : Ignore saved progress and fetch everything again}
        {--retry-missing : Ask INEC again for units it had no location for last time}
        {--force : Load into the campaign even after its setup freeze date}';

    protected $description = 'Fetch every polling unit of a state with INEC\'s GPS coordinates, as a PollProof unit list';

    public function handle(StructureImporter $importer, AuditLogger $audit): int
    {
        $stateId = InecLocator::stateId((string) $this->argument('state'));
        if (! $stateId) {
            $this->error('Unknown state. Use a name such as "niger" or the portal id (1 to 37).');

            return self::FAILURE;
        }
        $concurrency = max(1, min(100, (int) $this->option('concurrency')));
        $campaign = $this->option('campaign') ? Campaign::find($this->option('campaign')) : null;
        if ($this->option('campaign') && ! $campaign) {
            $this->error('No campaign with ID '.$this->option('campaign').'.');

            return self::FAILURE;
        }
        if ($campaign?->isFrozen() && ! $this->option('force')) {
            $this->error("Campaign setup was locked on {$campaign->freeze_at->format('j M Y')}. Add --force to load anyway.");

            return self::FAILURE;
        }

        $name = InecLocator::STATES[$stateId];
        $slug = strtolower(str_replace(' ', '-', $name));
        $dir = storage_path("app/inec/{$slug}");
        File::ensureDirectoryExists($dir);
        $hierarchyFile = "{$dir}/hierarchy.json";
        $coordsFile = "{$dir}/coordinates.json";
        if ($this->option('fresh')) {
            File::delete([$hierarchyFile, $coordsFile]);
        }
        $locator = new InecLocator(concurrency: $concurrency);
        $started = microtime(true);

        // 1. LGAs, wards and units.
        if (is_file($hierarchyFile)) {
            $lgas = json_decode(file_get_contents($hierarchyFile), true);
            $this->line("Using the saved list of units for {$name} (--fresh to fetch it again).");
        } else {
            $this->info("Fetching {$name}'s LGAs, wards and polling units from INEC…");
            $lgas = $locator->hierarchy($stateId, fn ($what, $n) => $this->line("  {$n} ".($what === 'lgas' ? 'LGAs, their wards fetched' : 'wards, their units fetched')));
            file_put_contents($hierarchyFile, json_encode($lgas));
        }
        $units = [];
        foreach ($lgas as $l) {
            foreach ($l['wards'] as $w) {
                foreach ($w['pus'] as $p) {
                    $units[] = ['key' => $p['id'], 'state' => $stateId, 'lga' => $l['id'], 'ward' => $w['id'], 'pu' => $p['id']];
                }
            }
        }
        $this->line(sprintf('  %d LGAs, %d wards, %d polling units', count($lgas), array_sum(array_map(fn ($l) => count($l['wards']), $lgas)), count($units)));

        // 2. Coordinates, resuming from what earlier runs saved.
        $coords = is_file($coordsFile) ? json_decode(file_get_contents($coordsFile), true) : [];
        $todo = array_values(array_filter($units, fn ($u) => ! array_key_exists($u['key'], $coords) || ($this->option('retry-missing') && $coords[$u['key']] === null)));
        if ($todo) {
            $this->info(sprintf('Fetching coordinates for %d units, %d at a time…', count($todo), $concurrency));
            $bar = $this->output->createProgressBar(count($todo));
            $bar->start();
            $locator->coordinates($todo, function (array $batch) use (&$coords, $coordsFile, $bar) {
                $coords = $batch + $coords;
                file_put_contents($coordsFile, json_encode($coords)); // resume point
                $bar->advance(count($batch));
            });
            $bar->finish();
            $this->newLine();
        } else {
            $this->line('Coordinates already fetched for every unit (--retry-missing or --fresh to ask again).');
        }

        // 3. A PollProof unit list (Structure → Import unit list takes the same file).
        $state = InecLocator::delimitationCode($stateId);
        $csv = storage_path("app/inec/{$slug}-polling-units.csv");
        $out = fopen($csv, 'w');
        fputcsv($out, ['lga_code', 'lga_name', 'ward_code', 'ward_name', 'pu_code', 'pu_name', 'latitude', 'longitude', 'registered_voters']);
        $withGps = 0;
        foreach ($lgas as $l) {
            foreach ($l['wards'] as $w) {
                foreach ($w['pus'] as $p) {
                    $c = $coords[$p['id']] ?? null;
                    $withGps += $c ? 1 : 0;
                    fputcsv($out, [$l['code'], self::title($l['name']), $w['code'], self::title($w['name']),
                        "{$state}-{$l['code']}-{$w['code']}-{$p['code']}", $p['name'], $c['lat'] ?? '', $c['lng'] ?? '', '']);
                }
            }
        }
        fclose($out);
        $notFetched = count(array_filter($units, fn ($u) => ! array_key_exists($u['key'], $coords)));

        $this->newLine();
        $this->table(['', ''], [
            ['Polling units', number_format(count($units))],
            ['With INEC coordinates', number_format($withGps).sprintf(' (%.1f%%)', count($units) ? $withGps / count($units) * 100 : 0)],
            ['INEC has no location for', number_format(count($units) - $withGps - $notFetched)],
            ['Not fetched (network errors)', number_format($notFetched).($notFetched ? ' — run again to resume' : '')],
            ['Time', sprintf('%.0f s', microtime(true) - $started)],
            ['Unit list', $csv],
        ]);

        // 4. Optionally load it into a campaign. Units INEC has no location for keep any pin they have.
        if ($campaign) {
            $summary = $importer->import($campaign, $csv);
            $audit->log(null, 'structure.imported_from_inec', null, $summary + ['state' => $name, 'with_gps' => $withGps], null, $campaign->id);
            $this->info(sprintf('Loaded into "%s": %d LGAs, %d wards, %d units; %d still without a location.', $campaign->name, $summary['lgas'], $summary['wards'], $summary['units'], $summary['missing_gps']));
        } else {
            $this->line('Load it with --campaign=<id>, or upload the file at Setup → Structure → Import unit list.');
        }

        return $notFetched ? self::FAILURE : self::SUCCESS;
    }

    private static function title(string $s): string
    {
        return preg_replace_callback('/\b([A-Za-z])([A-Za-z]*)/', fn ($m) => strtoupper($m[1]).strtolower($m[2]), $s);
    }
}
