<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lga;
use App\Models\PollingUnit;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports the state's polling-unit list from CSV with the header:
 * lga_code,lga_name,ward_code,ward_name,pu_code,pu_name,latitude,longitude,registered_voters
 * Re-importing is safe: rows are matched on their codes and updated; blank coordinates or voter
 * counts leave the unit's existing ones in place.
 */
final class StructureImporter
{
    public const COLUMNS = ['lga_code', 'lga_name', 'ward_code', 'ward_name', 'pu_code', 'pu_name', 'latitude', 'longitude', 'registered_voters'];

    private const REQUIRED = ['lga_code', 'lga_name', 'ward_code', 'ward_name', 'pu_code', 'pu_name'];

    /** @return array{lgas: int, wards: int, units: int, missing_gps: int} */
    public function import(Campaign $campaign, string $path): array
    {
        $lgas = $wards = [];
        $units = $missingGps = 0;
        DB::transaction(function () use ($campaign, $path, &$lgas, &$wards, &$units, &$missingGps) {
            foreach (Csv::rows($path, self::REQUIRED) as $line => $row) {
                foreach (['lga_code', 'ward_code', 'pu_code', 'pu_name'] as $required) {
                    if (($row[$required] ?? '') === '') {
                        throw ValidationException::withMessages(['file' => "Line {$line}: {$required} is empty."]);
                    }
                }

                $lga = $lgas[$row['lga_code']] ??= Lga::updateOrCreate(
                    ['campaign_id' => $campaign->id, 'code' => $row['lga_code']],
                    ['name' => $row['lga_name'] ?: $row['lga_code']],
                );
                $wardKey = $row['lga_code'].'/'.$row['ward_code'];
                $ward = $wards[$wardKey] ??= Ward::updateOrCreate(
                    ['lga_id' => $lga->id, 'code' => $row['ward_code']],
                    ['campaign_id' => $campaign->id, 'name' => $row['ward_name'] ?: $row['ward_code']],
                );

                $lat = is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null;
                $lng = is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null;
                // A blank location or voter count keeps what the unit already has (a pin a supervisor
                // dropped at the unit, say), so re-importing a list never wipes them.
                $attrs = ['ward_id' => $ward->id, 'name' => $row['pu_name']]
                    + ($lat !== null && $lng !== null ? ['latitude' => $lat, 'longitude' => $lng] : [])
                    + (is_numeric($row['registered_voters'] ?? null) ? ['registered_voters' => (int) $row['registered_voters']] : []);
                $unit = PollingUnit::updateOrCreate(['campaign_id' => $campaign->id, 'code' => $row['pu_code']], $attrs);
                if ($unit->latitude === null || $unit->longitude === null) {
                    $missingGps++;
                }
                $units++;
            }
        });

        return ['lgas' => count($lgas), 'wards' => count($wards), 'units' => $units, 'missing_gps' => $missingGps];
    }
}
