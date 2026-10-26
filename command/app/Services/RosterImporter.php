<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lga;
use App\Models\PollingUnit;
use App\Models\User;
use App\Models\Ward;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Imports polling-unit agents from CSV:
 *   name,phone,pu_code,lga_code,ward_code,reserve,code
 * Only name and phone are required. A row with pu_code is assigned to that unit; a reserve or a row
 * without a unit is kept in the ward given by lga_code + ward_code (for auto-assign or election-day gaps).
 * Rows are matched on phone, so re-uploading a corrected file updates people rather than duplicating them.
 * Bad rows are skipped and reported; good rows are saved.
 */
final class RosterImporter
{
    /** @return array{rows: int, assigned: int, reserves: int, unassigned: int, created: int, updated: int, rejected: list<array{line: int, name: string, phone: string, reason: string}>} */
    public function import(Campaign $campaign, string $path): array
    {
        $units = PollingUnit::where('campaign_id', $campaign->id)->with('ward')->get()->keyBy(fn ($u) => strtoupper($u->code));
        $lgas = Lga::where('campaign_id', $campaign->id)->get()->keyBy(fn ($l) => strtoupper($l->code));
        $wards = Ward::where('campaign_id', $campaign->id)->get()->keyBy(fn ($w) => $w->lga_id.'/'.strtoupper($w->code));
        $holders = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNotNull('polling_unit_id')
            ->get(['id', 'phone', 'code', 'polling_unit_id'])->keyBy('polling_unit_id');

        $summary = ['rows' => 0, 'assigned' => 0, 'reserves' => 0, 'unassigned' => 0, 'created' => 0, 'updated' => 0, 'rejected' => []];
        $seenPhones = $seenUnits = [];
        $nextCode = $this->nextCodeNumber($campaign);

        DB::transaction(function () use ($campaign, $path, $units, $lgas, $wards, $holders, &$summary, &$seenPhones, &$seenUnits, &$nextCode) {
            foreach (Csv::rows($path, ['name', 'phone']) as $line => $row) {
                $summary['rows']++;
                $reject = function (string $reason) use (&$summary, $line, $row) {
                    $summary['rejected'][] = ['line' => $line, 'name' => $row['name'] ?? '', 'phone' => $row['phone'] ?? '', 'reason' => $reason];
                };

                $phone = Phone::normalize($row['phone']);
                if ($row['name'] === '') {
                    $reject('Name is empty');

                    continue;
                }
                if (! $phone) {
                    $reject('Not a valid Nigerian mobile number');

                    continue;
                }
                if (isset($seenPhones[$phone])) {
                    $reject("Duplicate phone (also on line {$seenPhones[$phone]})");

                    continue;
                }
                $seenPhones[$phone] = $line;

                $existing = User::where('phone', $phone)->first();
                if ($existing && ($existing->campaign_id !== $campaign->id || $existing->role !== Role::Agent)) {
                    $reject($existing->campaign_id !== $campaign->id ? 'Phone is registered to another campaign' : 'Phone belongs to a '.$existing->role->label());

                    continue;
                }

                $reserve = Csv::truthy($row['reserve'] ?? '');
                $unit = null;
                $ward = null;
                if (! $reserve && ($row['pu_code'] ?? '') !== '') {
                    $unit = $units->get(strtoupper($row['pu_code']));
                    if (! $unit) {
                        $reject("Unknown polling unit {$row['pu_code']}");

                        continue;
                    }
                    if (isset($seenUnits[$unit->id])) {
                        $reject("{$unit->code} is already given to line {$seenUnits[$unit->id]}");

                        continue;
                    }
                    $holder = $holders->get($unit->id);
                    if ($holder && $holder->phone !== $phone) {
                        $reject("{$unit->code} already has agent {$holder->code}");

                        continue;
                    }
                    $seenUnits[$unit->id] = $line;
                    $ward = $unit->ward;
                } elseif (($row['lga_code'] ?? '') !== '' && ($row['ward_code'] ?? '') !== '') {
                    $lga = $lgas->get(strtoupper($row['lga_code']));
                    $ward = $lga ? $wards->get($lga->id.'/'.strtoupper($row['ward_code'])) : null;
                    if (! $ward) {
                        $reject("Unknown ward {$row['lga_code']} / {$row['ward_code']}");

                        continue;
                    }
                }

                $attrs = [
                    'name' => $row['name'],
                    'polling_unit_id' => $unit?->id,
                    'ward_id' => $ward?->id ?? $existing?->ward_id,
                    'lga_id' => $ward?->lga_id ?? $existing?->lga_id,
                    'is_reserve' => $reserve,
                ];
                if ($existing) {
                    $existing->update($attrs);
                    $summary['updated']++;
                } else {
                    $code = ($row['code'] ?? '') !== '' ? strtoupper($row['code']) : sprintf('AG-%04d', $nextCode++);
                    if (User::where('campaign_id', $campaign->id)->where('code', $code)->exists()) {
                        $reject("Agent code {$code} is already used");

                        continue;
                    }
                    User::create($attrs + ['campaign_id' => $campaign->id, 'role' => Role::Agent, 'phone' => $phone, 'code' => $code]);
                    $summary['created']++;
                }
                $summary[$unit ? 'assigned' : ($reserve ? 'reserves' : 'unassigned')]++;
            }
        });

        return $summary;
    }

    private function nextCodeNumber(Campaign $campaign): int
    {
        $max = User::where('campaign_id', $campaign->id)->where('code', 'like', 'AG-%')->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D/', '', $c))->max();

        return ($max ?? 0) + 1;
    }
}
