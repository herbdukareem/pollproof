<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Election;
use App\Models\Lga;
use App\Models\ResultSubmission;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;

/**
 * Running totals for a race from the campaign's own signed results, and the test the race is
 * won by:
 *
 * - spread (governorship): the most votes, plus 25% in two-thirds of the state's LGAs
 * - state_quarter (presidential): the most votes; nationally a candidate also needs 25% in
 *   two-thirds of the states, so this state counts towards it when we reach 25% here
 * - plurality (every other race): the most votes
 *
 * Totals are broken down by LGA, or by ward for a race inside a single LGA.
 */
final class TallyService
{
    public function summary(Election $election): array
    {
        $parties = $election->parties()->get();
        $codes = $parties->pluck('code')->all();
        $lgaIds = $election->lgaIds();
        $byWard = ! $election->isStatewide() && count($lgaIds) === 1;
        $areaCol = $byWard ? 'pu.ward_id' : 'w.lga_id';
        $unitIds = $election->units()->select('polling_units.id');

        $rows = DB::table('result_votes')
            ->join('result_submissions as r', 'r.id', '=', 'result_votes.result_submission_id')
            ->join('polling_units as pu', 'pu.id', '=', 'r.polling_unit_id')
            ->join('wards as w', 'w.id', '=', 'pu.ward_id')
            ->join('parties as p', 'p.id', '=', 'result_votes.party_id')
            ->where('r.election_id', $election->id)
            ->where('r.is_current', true)
            ->where('r.status', '!=', ResultSubmission::SENT_BACK)
            ->groupBy($areaCol, 'p.code')
            ->selectRaw("{$areaCol} as area_id, p.code, SUM(result_votes.votes) as votes")
            ->get();

        $unitCounts = DB::table('polling_units as pu')
            ->join('wards as w', 'w.id', '=', 'pu.ward_id')
            ->leftJoin('result_submissions as r', function ($j) use ($election) {
                $j->on('r.polling_unit_id', '=', 'pu.id')->where('r.election_id', $election->id)
                    ->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK);
            })
            ->whereIn('pu.id', $unitIds)
            ->groupBy($areaCol)
            ->selectRaw("{$areaCol} as area_id, COUNT(pu.id) as units, COUNT(r.id) as reported, SUM(COALESCE(pu.registered_voters, 0)) as registered, SUM(COALESCE(r.accredited, 0)) as accredited, SUM(COALESCE(r.total_cast, 0)) as cast, SUM(COALESCE(r.rejected, 0)) as rejected")
            ->get()->keyBy('area_id');

        $byArea = [];
        foreach ($rows as $row) {
            $byArea[$row->area_id][$row->code] = (int) $row->votes;
        }

        $places = $byWard
            ? Ward::whereIn('id', DB::table('polling_units')->whereIn('id', $unitIds)->select('ward_id'))->orderBy('code')->get(['id', 'code', 'name'])
            : Lga::whereIn('id', $lgaIds)->orderBy('code')->get(['id', 'code', 'name']);

        $totals = array_fill_keys($codes, 0);
        $areas = [];
        foreach ($places as $place) {
            $votes = array_replace(array_fill_keys($codes, 0), array_intersect_key($byArea[$place->id] ?? [], $totals));
            $valid = array_sum($votes);
            foreach ($votes as $code => $n) {
                $totals[$code] += $n;
            }
            $c = $unitCounts[$place->id] ?? null;
            $areas[] = [
                'id' => $place->id,
                'code' => $place->code,
                'name' => $place->name,
                'units' => (int) ($c->units ?? 0),
                'units_reported' => (int) ($c->reported ?? 0),
                // Registered covers every unit; the rest only units that have reported.
                'registered' => (int) ($c->registered ?? 0),
                'accredited' => (int) ($c->accredited ?? 0),
                'cast' => (int) ($c->cast ?? 0),
                'rejected' => (int) ($c->rejected ?? 0),
                'votes' => $votes,
                'share' => array_map(fn ($n) => $valid > 0 ? round($n / $valid, 4) : 0.0, $votes),
            ];
        }

        $areaCount = count($areas);
        $rule = $election->rule();
        // Two-thirds is stored as 0.6667; round before ceil so 3 LGAs needs 2, not 3.
        $required = (int) ceil(round($areaCount * $election->area_fraction, 2));
        $all = array_sum($totals);
        arsort($totals);
        $leader = array_key_first($totals);
        $tied = $leader !== null && count($totals) > 1 && array_values($totals)[0] === array_values($totals)[1];

        $candidates = [];
        foreach ($parties as $party) {
            $met = collect($areas)->filter(fn ($l) => ($l['share'][$party->code] ?? 0) >= $election->share_threshold)->count();
            $share = $all > 0 ? round($totals[$party->code] / $all, 4) : 0.0;
            $candidates[] = [
                'party' => $party->code,
                'name' => $party->name,
                'candidate' => $party->pivot->candidate,
                'is_ours' => $party->is_ours,
                'votes' => $totals[$party->code],
                'share' => $share,
                'lgas_meeting_threshold' => $met,
                'meets_spread' => $rule !== 'spread' || $met >= $required,
                'meets_quarter' => $share >= $election->share_threshold,
                'leads' => $party->code === $leader && $totals[$party->code] > 0 && ! $tied,
            ];
        }
        usort($candidates, fn ($a, $b) => $b['votes'] <=> $a['votes']);

        return [
            'election' => self::race($election),
            'rule' => $rule,
            'area_level' => $byWard ? 'ward' : 'lga',
            'units' => (int) collect($unitCounts)->sum('units'),
            'units_reported' => (int) collect($unitCounts)->sum('reported'),
            'lga_count' => $areaCount,
            'lgas_required' => $required,
            'threshold' => $election->share_threshold,
            'candidates' => $candidates,
            'lgas' => $areas,
        ];
    }

    /**
     * Every race at a glance: how far counting has got and who leads, by our own results.
     *
     * @return list<array<string, mixed>>
     */
    public function board(Campaign $campaign): array
    {
        $races = $campaign->elections()->with(['areas', 'parties'])->get();
        $reported = DB::table('result_submissions')->whereIn('election_id', $races->pluck('id'))
            ->where('is_current', true)->where('status', '!=', ResultSubmission::SENT_BACK)
            ->groupBy('election_id')->selectRaw('election_id, COUNT(*) as n, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as waiting', [ResultSubmission::PENDING_REVIEW])
            ->get()->keyBy('election_id');
        $votes = DB::table('result_votes as rv')
            ->join('result_submissions as r', 'r.id', '=', 'rv.result_submission_id')
            ->join('parties as p', 'p.id', '=', 'rv.party_id')
            ->whereIn('r.election_id', $races->pluck('id'))->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK)
            ->groupBy('r.election_id', 'p.code')->selectRaw('r.election_id, p.code, SUM(rv.votes) as votes')
            ->get()->groupBy('election_id');

        return $races->map(function (Election $e) use ($reported, $votes) {
            $tally = collect($votes[$e->id] ?? [])->mapWithKeys(fn ($v) => [$v->code => (int) $v->votes]);
            $all = $tally->sum();
            $ballot = $e->parties->map(fn ($p) => [
                'party' => $p->code, 'name' => $p->name, 'candidate' => $p->pivot->candidate, 'is_ours' => $p->is_ours,
                'votes' => (int) ($tally[$p->code] ?? 0), 'share' => $all > 0 ? round(($tally[$p->code] ?? 0) / $all, 4) : 0.0,
            ])->sortByDesc('votes')->values();
            $ours = $ballot->firstWhere('is_ours', true);
            $top = $ballot->first();
            $second = $ballot->get(1);
            $spread = $e->rule() === 'spread' && $all > 0 ? $this->summary($e) : null;

            return self::race($e) + [
                'units' => $e->units()->count(),
                'units_reported' => (int) ($reported[$e->id]->n ?? 0),
                'waiting' => (int) ($reported[$e->id]->waiting ?? 0),
                'votes_counted' => $all,
                'candidates' => $ballot->take(3)->all(),
                'ours' => $ours,
                'standing' => match (true) {
                    $all === 0 || ! $ours => 'none',
                    $top && $second && $top['votes'] === $second['votes'] && $top['votes'] === $ours['votes'] => 'tied',
                    $top['party'] === $ours['party'] => 'leading',
                    default => 'trailing',
                },
                'margin' => $ours && $top ? ($top['party'] === $ours['party'] ? $ours['votes'] - (int) ($second['votes'] ?? 0) : $ours['votes'] - $top['votes']) : 0,
                'spread' => $spread ? [
                    'met' => collect($spread['candidates'])->firstWhere('is_ours', true)['lgas_meeting_threshold'] ?? 0,
                    'required' => $spread['lgas_required'],
                    'count' => $spread['lga_count'],
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Each polling unit in the race that has GPS, with its current result for the race, so the
     * map can total them by whatever boundaries it draws. Compact rows: [lat, lng, reported (0/1),
     * registered, accredited, cast, rejected, votes per party...] in the order of `parties`.
     *
     * @return array{parties: list<array{code: string, name: string, is_ours: bool}>, fields: list<string>, points: list<list<float|int>>}
     */
    public function points(Election $election): array
    {
        $parties = $election->parties()->get(['parties.id', 'code', 'name', 'is_ours']);
        $slot = $parties->pluck('id')->flip()->all(); // party id => column

        $units = DB::table('polling_units as pu')
            ->leftJoin('result_submissions as r', function ($j) use ($election) {
                $j->on('r.polling_unit_id', '=', 'pu.id')->where('r.election_id', $election->id)
                    ->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK);
            })
            ->whereIn('pu.id', $election->units()->select('polling_units.id'))
            ->whereNotNull('pu.latitude')->whereNotNull('pu.longitude')
            ->orderBy('pu.id')
            ->get(['pu.latitude', 'pu.longitude', 'pu.registered_voters', 'r.id as rid', 'r.accredited', 'r.total_cast', 'r.rejected']);

        $votes = [];
        DB::table('result_votes as rv')
            ->join('result_submissions as r', 'r.id', '=', 'rv.result_submission_id')
            ->where('r.election_id', $election->id)->where('r.is_current', true)->where('r.status', '!=', ResultSubmission::SENT_BACK)
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

    /** A race as the command room shows it. */
    public static function race(Election $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => $e->label(),
            'name' => $e->name,
            'code' => $e->code,
            'election_date' => $e->election_date->toDateString(),
            'is_rerun' => $e->is_rerun,
            'rule' => $e->rule(),
            'final_level' => $e->finalLevel(),
            'statewide' => $e->isStatewide(),
            'area' => $e->areaSummary(),
            'declared_at' => $e->declared_at,
        ];
    }
}
