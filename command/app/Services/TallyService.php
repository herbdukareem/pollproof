<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\ResultSubmission;
use Illuminate\Support\Facades\DB;

/**
 * Running totals from the campaign's own signed results, and the constitutional test for a
 * governorship win: the most votes, plus at least 25% of votes in two-thirds of the LGAs.
 */
final class TallyService
{
    public function summary(Campaign $campaign): array
    {
        $parties = $campaign->parties()->get();

        $rows = DB::table('result_votes')
            ->join('result_submissions as r', 'r.id', '=', 'result_votes.result_submission_id')
            ->join('polling_units as pu', 'pu.id', '=', 'r.polling_unit_id')
            ->join('wards as w', 'w.id', '=', 'pu.ward_id')
            ->join('parties as p', 'p.id', '=', 'result_votes.party_id')
            ->where('r.campaign_id', $campaign->id)
            ->where('r.is_current', true)
            ->where('r.status', '!=', ResultSubmission::SENT_BACK)
            ->groupBy('w.lga_id', 'p.code')
            ->selectRaw('w.lga_id, p.code, SUM(result_votes.votes) as votes')
            ->get();

        $unitCounts = DB::table('polling_units as pu')
            ->join('wards as w', 'w.id', '=', 'pu.ward_id')
            ->leftJoin('result_submissions as r', function ($j) {
                $j->on('r.polling_unit_id', '=', 'pu.id')->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK);
            })
            ->where('pu.campaign_id', $campaign->id)
            ->groupBy('w.lga_id')
            ->selectRaw('w.lga_id, COUNT(pu.id) as units, COUNT(r.id) as reported')
            ->get()->keyBy('lga_id');

        $byLga = [];
        foreach ($rows as $row) {
            $byLga[$row->lga_id][$row->code] = (int) $row->votes;
        }

        $totals = array_fill_keys($parties->pluck('code')->all(), 0);
        $lgas = [];
        foreach ($campaign->lgas()->orderBy('code')->get() as $lga) {
            $votes = array_replace(array_fill_keys($parties->pluck('code')->all(), 0), $byLga[$lga->id] ?? []);
            $valid = array_sum($votes);
            foreach ($votes as $code => $n) {
                $totals[$code] += $n;
            }
            $lgas[] = [
                'id' => $lga->id,
                'code' => $lga->code,
                'name' => $lga->name,
                'units' => (int) ($unitCounts[$lga->id]->units ?? 0),
                'units_reported' => (int) ($unitCounts[$lga->id]->reported ?? 0),
                'votes' => $votes,
                'share' => array_map(fn ($n) => $valid > 0 ? round($n / $valid, 4) : 0.0, $votes),
            ];
        }

        $lgaCount = count($lgas);
        // Two-thirds is stored as 0.6667; round before ceil so 3 LGAs needs 2, not 3.
        $required = (int) ceil(round($lgaCount * $campaign->lga_count_fraction, 2));
        arsort($totals);
        $leader = array_key_first($totals);

        $candidates = [];
        foreach ($parties as $party) {
            $met = collect($lgas)->filter(fn ($l) => ($l['share'][$party->code] ?? 0) >= $campaign->lga_share_threshold)->count();
            $candidates[] = [
                'party' => $party->code,
                'name' => $party->name,
                'is_ours' => $party->is_ours,
                'votes' => $totals[$party->code],
                'lgas_meeting_threshold' => $met,
                'meets_spread' => $met >= $required,
                'leads' => $party->code === $leader && $totals[$party->code] > 0,
            ];
        }
        usort($candidates, fn ($a, $b) => $b['votes'] <=> $a['votes']);

        $units = (int) collect($unitCounts)->sum('units');
        $reported = (int) collect($unitCounts)->sum('reported');

        return [
            'units' => $units,
            'units_reported' => $reported,
            'lga_count' => $lgaCount,
            'lgas_required' => $required,
            'threshold' => $campaign->lga_share_threshold,
            'candidates' => $candidates,
            'lgas' => $lgas,
        ];
    }
}
