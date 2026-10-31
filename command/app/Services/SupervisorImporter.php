<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Lga;
use App\Models\Ward;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * Imports LGA and ward supervisors from CSV:
 *   name,phone,level,lga_code,ward_code,backup
 * level is "lga" or "ward". Each LGA has one supervisor and optionally one backup; each ward has one supervisor.
 * A new supervisor for a post replaces the previous holder, who stays registered but unassigned.
 */
final class SupervisorImporter
{
    public function __construct(private readonly SupervisorAssigner $assigner) {}

    /** @return array{rows: int, lga: int, ward: int, replaced: int, blank: int, rejected: list<array{line: int, name: string, phone: string, reason: string}>} */
    public function import(Campaign $campaign, string $path): array
    {
        $lgas = Lga::where('campaign_id', $campaign->id)->get()->keyBy(fn ($l) => Csv::codeKey($l->code));
        $wards = Ward::where('campaign_id', $campaign->id)->get()->keyBy(fn ($w) => $w->lga_id.'/'.Csv::codeKey($w->code));
        $summary = ['rows' => 0, 'lga' => 0, 'ward' => 0, 'replaced' => 0, 'blank' => 0, 'rejected' => []];
        $seen = [];

        DB::transaction(function () use ($campaign, $path, $lgas, $wards, &$summary, &$seen) {
            foreach (Csv::rows($path, ['name', 'phone', 'level', 'lga_code']) as $line => $row) {
                // A post left empty in the template: nothing to do.
                if ($row['name'] === '' && $row['phone'] === '') {
                    $summary['blank']++;

                    continue;
                }
                $summary['rows']++;
                $reject = function (string $reason) use (&$summary, $line, $row) {
                    $summary['rejected'][] = ['line' => $line, 'name' => $row['name'], 'phone' => $row['phone'], 'reason' => $reason];
                };

                $phone = Phone::normalize($row['phone']);
                $level = strtolower($row['level']);
                $lga = $lgas->get(Csv::codeKey($row['lga_code']));
                $ward = $lga && ($row['ward_code'] ?? '') !== '' ? $wards->get($lga->id.'/'.Csv::codeKey($row['ward_code'])) : null;
                $error = match (true) {
                    $row['name'] === '' => 'Name is empty',
                    ! $phone => 'Not a valid Nigerian mobile number',
                    isset($seen[$phone]) => "Duplicate phone (also on line {$seen[$phone]})",
                    ! in_array($level, ['lga', 'ward'], true) => 'Level must be "lga" or "ward"',
                    ! $lga => "Unknown LGA {$row['lga_code']}",
                    $level === 'ward' && ! $ward => "Unknown ward {$row['lga_code']} / ".($row['ward_code'] ?? ''),
                    default => null,
                };
                if ($error) {
                    $reject($error);

                    continue;
                }
                $seen[$phone] = $line;

                $result = $this->assigner->assign($campaign, $row['name'], $phone, $level, $lga, $ward, Csv::truthy($row['backup'] ?? ''));
                if (is_string($result)) {
                    $reject($result);

                    continue;
                }
                $summary[$level]++;
                $summary['replaced'] += $result['replaced'] ? 1 : 0;
            }
        });

        return $summary;
    }
}
