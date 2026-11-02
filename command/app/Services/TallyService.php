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
            ->selectRaw('w.lga_id, COUNT(pu.id) as units, COUNT(r.id) as reported, SUM(COALESCE(pu.registered_voters, 0)) as registered, SUM(COALESCE(r.accredited, 0)) as accredited, SUM(COALESCE(r.total_cast, 0)) as cast, SUM(COALESCE(r.rejected, 0)) as rejected')
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
                // Registered covers every unit; the rest only units that have reported.
                'registered' => (int) ($unitCounts[$lga->id]->registered ?? 0),
                'accredited' => (int) ($unitCounts[$lga->id]->accredited ?? 0),
                'cast' => (int) ($unitCounts[$lga->id]->cast ?? 0),
                'rejected' => (int) ($unitCounts[$lga->id]->rejected ?? 0),
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

    /**
     * Each polling unit that has GPS, with its current result, so the map can total them by
     * whatever boundaries it draws. Compact rows: [lat, lng, reported (0/1), registered,
     * accredited, cast, rejected, votes per party...]
     * with the votes in the order of `parties`.
     *
     * @return array{parties: list<array{code: string, name: string, is_ours: bool}>, points: list<list<float|int>>}
     */
    public function points(Campaign $campaign): array
    {
        $parties = $campaign->parties()->get(['id', 'code', 'name', 'is_ours']);
        $slot = $parties->pluck('id')->flip()->all(); // party id => column

        $units = DB::table('polling_units as pu')
            ->leftJoin('result_submissions as r', function ($j) {
                $j->on('r.polling_unit_id', '=', 'pu.id')->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK);
            })
            ->where('pu.campaign_id', $campaign->id)
            ->whereNotNull('pu.latitude')->whereNotNull('pu.longitude')
            ->orderBy('pu.id')
            ->get(['pu.latitude', 'pu.longitude', 'pu.registered_voters', 'r.id as rid', 'r.accredited', 'r.total_cast', 'r.rejected']);

        $votes = [];
        DB::table('result_votes as rv')
            ->join('result_submissions as r', 'r.id', '=', 'rv.result_submission_id')
            ->where('r.campaign_id', $campaign->id)->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK)
            ->get(['rv.result_submission_id', 'rv.party_id', 'rv.votes'])
            ->each(function ($v) use (&$votes, $slot) {
                if (isset($slot[$v->party_id])) {
                    $votes[$v->result_submission_id][$slot[$v->party_id]] = (int) $v->votes;
                }
            });

        $blank = array_fill(0, $parties->count(), 0);

        return [
            'parties' => $parties->map(fn ($p) => ['code' => $p->code, 'name' => $p->name, 'is_ours' => (bool) $p->is_ours])->values()->all(),
            'fields' => ['lat', 'lng', 'reported', 'registered', 'accredited', 'cast', 'rejected'],
            'points' => $units->map(fn ($u) => [round((float) $u->latitude, 5), round((float) $u->longitude, 5), $u->rid ? 1 : 0,
                (int) $u->registered_voters, (int) $u->accredited, (int) $u->total_cast, (int) $u->rejected,
                ...array_replace($blank, $votes[$u->rid] ?? [])])->all(),
        ];
    }
}
