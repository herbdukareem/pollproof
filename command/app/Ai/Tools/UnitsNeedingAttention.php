<?php

namespace App\Ai\Tools;

use App\Models\Alert;
use App\Models\CheckIn;
use App\Models\PollingUnit;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class UnitsNeedingAttention extends PollProofTool
{
    protected string $name = 'units_needing_attention';

    protected ?string $description = 'Polling units with one kind of problem, with how many and a list: no agent, agent not checked in today, no result yet for a race, sent back to the agent, votes over accreditation, IReV different or missing, flagged by a reviewer.';

    protected function properties(): array
    {
        return [
            new ToolProperty('problem', PropertyType::STRING, 'Which problem.', true, ['no_agent', 'not_checked_in', 'no_result', 'sent_back', 'overvote', 'irev_differs', 'irev_missing', 'flagged']),
            new ToolProperty('lga', PropertyType::STRING, 'Only this LGA.', false),
            new ToolProperty('race', PropertyType::STRING, 'For results: a race code or name; the main race if empty.', false),
            new ToolProperty('limit', PropertyType::INTEGER, 'How many units to list, up to 40. Default 15.', false),
        ];
    }

    public function __invoke(string $problem, ?string $lga = null, ?string $race = null, ?int $limit = 15): array
    {
        $l = $lga ? $this->lga($lga) : null;
        if ($lga && ! $l) {
            return $this->notFound('LGA');
        }
        $election = $this->race($race);
        $units = $this->units()->when($l, fn ($q) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $l->id)))
            ->when($election, fn ($q) => $q->whereIn('polling_units.id', $election->units()->select('polling_units.id')));
        $current = fn () => ResultSubmission::select('polling_unit_id')->where('election_id', $election?->id ?? 0)->where('is_current', true);
        $alerted = fn (string $type) => Alert::where('campaign_id', $this->campaignId())->where('type', $type)->where('status', '!=', 'resolved')
            ->get()->map(fn ($a) => $a->subject instanceof PollingUnit ? $a->subject->id : ($a->subject instanceof ResultSubmission ? $a->subject->polling_unit_id : null))->filter();
        $checkedIn = CheckIn::where('campaign_id', $this->campaignId())->today()->get()->filter->counts()->pluck('polling_unit_id');

        $units = match ($problem) {
            'no_agent' => $units->whereDoesntHave('agent'),
            'not_checked_in' => $units->whereHas('agent')->whereNotIn('polling_units.id', $checkedIn),
            'no_result' => $units->whereNotIn('polling_units.id', $current()),
            'sent_back' => $units->whereIn('polling_units.id', $current()->where('status', ResultSubmission::SENT_BACK)),
            'overvote' => $units->whereIn('polling_units.id', $alerted('overvote')),
            'irev_differs' => $units->whereIn('polling_units.id', $alerted('irev_mismatch')),
            'irev_missing' => $units->whereIn('polling_units.id', $alerted('irev_missing')),
            'flagged' => $units->whereIn('polling_units.id', ResultFlag::select('polling_unit_id')->where('status', ResultFlag::OPEN)),
            default => null,
        };
        if (! $units) {
            return ['message' => 'Unknown problem.'];
        }
        $total = (clone $units)->count();

        return [
            'problem' => $problem, 'race' => $election?->name, 'area' => $l?->name ?? 'everywhere you can see', 'count' => $total,
            'by_lga' => (clone $units)->join('wards', 'wards.id', '=', 'polling_units.ward_id')->join('lgas', 'lgas.id', '=', 'wards.lga_id')
                ->selectRaw('lgas.name, count(*) n')->groupBy('lgas.name')->orderByDesc('n')->limit(10)->pluck('n', 'name'),
            'units' => $units->with('ward.lga', 'agent')->orderBy('code')->take(min(40, max(1, $limit ?? 15)))->get()
                ->map(fn ($u) => "{$u->code} {$u->name} ({$u->ward->name}, {$u->ward->lga->name})".($u->agent ? ", agent {$u->agent->code}" : ', no agent'))->all(),
        ];
    }
}
