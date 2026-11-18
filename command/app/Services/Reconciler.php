<?php

namespace App\Services;

use App\Models\CollationEntry;
use App\Models\Election;
use App\Models\IrevEntry;
use App\Models\Lga;
use App\Models\ResultSubmission;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;

/**
 * Compares official figures (collation sheets, IReV) against the campaign's own
 * signed unit results for the same race, and raises an alert for every difference.
 */
final class Reconciler
{
    public function __construct(private readonly AlertService $alerts) {}

    /**
     * The race's units covered by a collation sheet: a ward, an LGA, or the whole race.
     *
     * @return list<int>
     */
    public function unitIdsInScope(Election $election, string $level, ?int $scopeId): array
    {
        $q = $election->units();
        if ($level === CollationEntry::WARD) {
            $q->where('ward_id', $scopeId);
        } elseif ($level === CollationEntry::LGA) {
            $q->whereIn('ward_id', Ward::where('lga_id', $scopeId)->select('id'));
        }

        return $q->pluck('id')->all();
    }

    /**
     * Sum of our current, not-sent-back unit results.
     *
     * @param  list<int>  $unitIds
     * @return array{votes: array<string,int>, rejected: int, accredited: int, total_cast: int, units_reported: int}
     */
    public function ourFigures(Election $election, array $unitIds): array
    {
        $base = ResultSubmission::where('result_submissions.election_id', $election->id)
            ->whereIn('result_submissions.polling_unit_id', $unitIds)
            ->where('is_current', true)
            ->where('status', '!=', ResultSubmission::SENT_BACK);

        $votes = DB::table('result_votes')
            ->join('parties', 'parties.id', '=', 'result_votes.party_id')
            ->whereIn('result_submission_id', (clone $base)->select('result_submissions.id'))
            ->groupBy('parties.code')
            ->selectRaw('parties.code as code, SUM(result_votes.votes) as total')
            ->pluck('total', 'code')
            ->map(fn ($v) => (int) $v)
            ->all();

        $totals = (clone $base)->selectRaw('COUNT(*) as n, COALESCE(SUM(rejected),0) as rej, COALESCE(SUM(accredited),0) as acc, COALESCE(SUM(total_cast),0) as cast')->first();

        return [
            'votes' => $votes,
            'rejected' => (int) $totals->rej,
            'accredited' => (int) $totals->acc,
            'total_cast' => (int) $totals->cast,
            'units_reported' => (int) $totals->n,
        ];
    }

    /** Compare and raise or clear alerts. @return array<string,mixed> */
    public function checkCollation(CollationEntry $entry): array
    {
        $comparison = $this->compareCollation($entry);
        $label = $comparison['scope'];
        $diffs = $comparison['differences'];
        $complete = $comparison['coverage_complete'];
        $unitCount = $comparison['units_in_scope'];

        $election = $entry->election;
        $key = "collation:{$entry->election_id}:{$entry->level}:".($entry->scope_id ?? 'state');
        if ($diffs) {
            $parts = collect($diffs)->map(fn ($d, $c) => sprintf('%s %+d', $c, $d))->implode(', ');
            $this->alerts->raise($entry->campaign_id, 'collation_mismatch', $complete ? 'high' : 'medium', $key,
                "{$label}: declared figures differ from our units",
                $complete
                    ? "Declared minus our signed results: {$parts}."
                    : "Declared minus our results so far: {$parts}. Only {$comparison['units_reported']} of {$unitCount} units have reported, so some difference may be expected.",
                $entry, $comparison, $election);
        } else {
            $this->alerts->clear($entry->campaign_id, $key);
        }

        $overKey = "overvote:{$entry->election_id}:{$entry->level}:".($entry->scope_id ?? 'state');
        if ($complete && $entry->total_cast > $comparison['our_accredited']) {
            $this->alerts->raise($entry->campaign_id, 'overvote', 'high', $overKey,
                "{$label}: declared votes exceed accreditation",
                "The sheet declares {$entry->total_cast} votes cast; our units recorded {$comparison['our_accredited']} accredited voters.",
                $entry, $comparison, $election);
        } else {
            $this->alerts->clear($entry->campaign_id, $overKey);
        }

        return $comparison;
    }

    /** Pure comparison with no side effects. @return array<string,mixed> */
    public function compareCollation(CollationEntry $entry): array
    {
        $unitIds = $this->unitIdsInScope($entry->election, $entry->level, $entry->scope_id);
        $ours = $this->ourFigures($entry->election, $unitIds);
        // A sheet whose ward or LGA has since been taken out of the structure has nothing to check against.
        $missing = $this->scopeMissing($entry);
        $complete = ! $missing && $ours['units_reported'] === count($unitIds);

        $diffs = [];
        foreach (array_unique(array_merge(array_keys($entry->votes), array_keys($ours['votes']))) as $code) {
            $diff = (int) ($entry->votes[$code] ?? 0) - (int) ($ours['votes'][$code] ?? 0);
            if ($diff !== 0) {
                $diffs[$code] = $diff;
            }
        }

        $label = $this->scopeLabel($entry);
        $comparison = [
            'level' => $entry->level,
            'scope' => $label,
            'declared' => $entry->votes,
            'ours' => $ours['votes'],
            'differences' => $diffs,
            'declared_total_cast' => $entry->total_cast,
            'our_total_cast' => $ours['total_cast'],
            'our_accredited' => $ours['accredited'],
            'units_in_scope' => count($unitIds),
            'units_reported' => $ours['units_reported'],
            'coverage_complete' => $complete,
            'scope_missing' => $missing,
        ];

        return $comparison;
    }

    /** Compare an IReV entry with our current result for the unit. Pure: no alerts. */
    public function compareIrev(IrevEntry $entry): array
    {
        if ($entry->not_on_irev) {
            return ['status' => 'not_on_irev'];
        }
        $ours = $entry->pollingUnit->currentResultFor($entry->election_id);
        if (! $ours) {
            return ['status' => 'no_agent_result', 'irev' => $entry->votes];
        }

        $ourVotes = $ours->votesByParty();
        $diffs = [];
        foreach (array_unique(array_merge(array_keys($entry->votes ?? []), array_keys($ourVotes))) as $code) {
            $diff = (int) ($entry->votes[$code] ?? 0) - (int) ($ourVotes[$code] ?? 0);
            if ($diff !== 0) {
                $diffs[$code] = $diff;
            }
        }
        if ($entry->accredited !== null && $entry->accredited !== $ours->accredited) {
            $diffs['accredited'] = $entry->accredited - $ours->accredited;
        }

        return ['status' => $diffs ? 'differs' : 'match', 'irev' => $entry->votes, 'ours' => $ourVotes, 'differences' => $diffs];
    }

    /** Compare and raise or clear alerts. */
    public function checkIrev(IrevEntry $entry): array
    {
        $unit = $entry->pollingUnit;
        $missingKey = "irev_missing:unit:{$unit->id}:race:{$entry->election_id}";
        $mismatchKey = "irev_mismatch:unit:{$unit->id}:race:{$entry->election_id}";
        $comparison = $this->compareIrev($entry);

        if ($comparison['status'] === 'not_on_irev') {
            $this->alerts->clear($entry->campaign_id, $mismatchKey);
            $this->alerts->raise($entry->campaign_id, 'irev_missing', 'medium', $missingKey,
                "{$unit->code}: result not on IReV",
                'Checked at '.$entry->seen_at->format('g:i a').' and the result was not uploaded.',
                $entry, ['seen_at' => $entry->seen_at->toIso8601String()], $entry->election);

            return $comparison;
        }
        $this->alerts->clear($entry->campaign_id, $missingKey);

        if ($comparison['status'] === 'differs') {
            $parts = collect($comparison['differences'])->map(fn ($d, $c) => sprintf('%s %+d', $c, $d))->implode(', ');
            $this->alerts->raise($entry->campaign_id, 'irev_mismatch', 'high', $mismatchKey,
                "{$unit->code}: IReV differs from our signed result",
                "IReV minus our result: {$parts}.", $entry, $comparison, $entry->election);
        } else {
            $this->alerts->clear($entry->campaign_id, $mismatchKey);
        }

        return $comparison;
    }

    /** Re-check every official figure that covers this unit once a new result arrives. */
    public function afterUnitResult(ResultSubmission $result): void
    {
        $unit = $result->pollingUnit()->with('ward')->first();
        $scopes = [
            [CollationEntry::WARD, $unit->ward_id],
            [CollationEntry::LGA, $unit->ward->lga_id],
            [CollationEntry::STATE, null],
        ];
        foreach ($scopes as [$level, $scopeId]) {
            $entry = $this->latestCollation($result->election, $level, $scopeId);
            if ($entry) {
                $this->checkCollation($entry);
            }
        }

        $irev = IrevEntry::where('polling_unit_id', $unit->id)->where('election_id', $result->election_id)->latest('seen_at')->latest('id')->first();
        if ($irev) {
            $this->checkIrev($irev);
        }
    }

    public function latestCollation(Election $election, string $level, ?int $scopeId): ?CollationEntry
    {
        return CollationEntry::where('election_id', $election->id)
            ->where('level', $level)
            ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scopeId))
            ->orderByDesc('announced_at')->orderByDesc('id')
            ->first();
    }

    /**
     * The ward or LGA a sheet was declared for. Collation sheets name their area by id without a
     * foreign key, so an area removed from the structure afterwards (a re-import, or rows deleted in
     * the database) leaves the sheet pointing at nothing; it is labelled as such rather than failing.
     */
    private function scopeLabel(CollationEntry $entry): string
    {
        return match ($entry->level) {
            CollationEntry::WARD => ($ward = Ward::with('lga')->find($entry->scope_id))
                ? $ward->name.($ward->lga ? " ({$ward->lga->name})" : '')
                : "Removed ward (#{$entry->scope_id})",
            CollationEntry::LGA => Lga::find($entry->scope_id)?->name ?? "Removed LGA (#{$entry->scope_id})",
            default => $entry->election->isStatewide() ? 'State' : $entry->election->name,
        };
    }

    /** True when the sheet's ward or LGA is no longer in the structure. */
    public function scopeMissing(CollationEntry $entry): bool
    {
        return match ($entry->level) {
            CollationEntry::WARD => ! Ward::whereKey($entry->scope_id)->exists(),
            CollationEntry::LGA => ! Lga::whereKey($entry->scope_id)->exists(),
            default => false,
        };
    }
}
