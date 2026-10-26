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
 * Re-importing is safe: rows are matched on their codes and updated.
 */
final class StructureImporter
{
    private const COLUMNS = ['lga_code', 'lga_name', 'ward_code', 'ward_name', 'pu_code', 'pu_name', 'latitude', 'longitude', 'registered_voters'];

    /** @return array{lgas: int, wards: int, units: int, missing_gps: int} */
    public function import(Campaign $campaign, string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($handle) ?: []);
        if (array_diff(array_slice(self::COLUMNS, 0, 6), $header)) {
            throw ValidationException::withMessages(['file' => 'CSV header must include: '.implode(', ', self::COLUMNS)]);
        }

        $lgas = $wards = [];
        $units = $missingGps = 0;
        DB::transaction(function () use ($campaign, $handle, $header, &$lgas, &$wards, &$units, &$missingGps) {
            $line = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }
                $row = array_combine($header, array_pad(array_map('trim', $cells), count($header), ''));
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
                if ($lat === null || $lng === null) {
                    $missingGps++;
                }
                PollingUnit::updateOrCreate(
                    ['campaign_id' => $campaign->id, 'code' => $row['pu_code']],
                    [
                        'ward_id' => $ward->id,
                        'name' => $row['pu_name'],
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'registered_voters' => is_numeric($row['registered_voters'] ?? null) ? (int) $row['registered_voters'] : null,
                    ],
                );
                $units++;
            }
        });
        fclose($handle);

        return ['lgas' => count($lgas), 'wards' => count($wards), 'units' => $units, 'missing_gps' => $missingGps];
    }
}
