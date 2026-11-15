<?php

namespace App\Ai\Tools;

use App\Models\Alert;
use App\Models\CheckIn;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class AreaReport extends PollProofTool
{
    protected string $name = 'area_report';

    protected ?string $description = 'Everything about one LGA or ward: units, agents present today, results in for a race, votes by party there, open incidents and alerts.';

    protected function properties(): array
    {
        return [
            new ToolProperty('lga', PropertyType::STRING, 'The LGA name or code.', true),
            new ToolProperty('ward', PropertyType::STRING, 'A ward in that LGA, to narrow it down.', false),
            new ToolProperty('race', PropertyType::STRING, 'A race code or name; the main race if empty.', false),
        ];
    }

    public function __invoke(string $lga, ?string $ward = null, ?string $race = null): array
    {
        $l = $this->lga($lga);
        if (! $l) {
            return $this->notFound('LGA');
        }
        $w = $ward ? $this->ward($ward, $l) : null;
        if ($ward && ! $w) {
            return $this->notFound("ward in {$l->name}");
        }
        $election = $this->race($race);
        $units = $this->units()->when($w, fn ($q) => $q->where('ward_id', $w->id), fn ($q) => $q->whereHas('ward', fn ($x) => $x->where('lga_id', $l->id)))
            ->when($election, fn ($q) => $q->whereIn('polling_units.id', $election->units()->select('polling_units.id')))
            ->with('agent')->get();
        $ids = $units->pluck('id');
        $present = CheckIn::whereIn('polling_unit_id', $ids)->today()->get()->filter->counts()->pluck('polling_unit_id')->unique();
        $results = $election ? ResultSubmission::whereIn('polling_unit_id', $ids)->where('election_id', $election->id)->where('is_current', true)->with('votes.party')->get() : collect();
        $votes = [];
        foreach ($results as $r) {
            foreach ($r->votesByParty() as $party => $n) {
                $votes[$party] = ($votes[$party] ?? 0) + $n;
            }
        }
        arsort($votes);

        return [
            'area' => $w ? "{$w->name} ward, {$l->name}" : "{$l->name} LGA",
            'race' => $election?->name,
            'units' => $units->count(),
            'units_without_agent' => $units->whereNull('agent')->count(),
            'agents_checked_in_today' => $present->count(),
            'results_in' => $results->count(),
            'results_verified' => $results->where('status', ResultSubmission::APPROVED)->count(),
            'results_sent_back' => $results->where('status', ResultSubmission::SENT_BACK)->count(),
            'votes_by_party' => $votes,
            'open_incidents' => Incident::whereIn('polling_unit_id', $ids)->where('status', '!=', 'resolved')->count(),
            'open_alerts_on_these_units' => Alert::where('campaign_id', $this->campaignId())->where('status', '!=', 'resolved')
                ->where(fn ($q) => $q->where(fn ($u) => $u->where('subject_type', (new PollingUnit)->getMorphClass())->whereIn('subject_id', $ids))
                    ->orWhere(fn ($r) => $r->where('subject_type', (new ResultSubmission)->getMorphClass())->whereIn('subject_id', $results->pluck('id'))))->count(),
            'units_with_no_result_yet' => $election ? $units->whereNotIn('id', $results->pluck('polling_unit_id'))->take(15)->map(fn ($u) => "{$u->code} {$u->name}".($u->agent ? '' : ' (no agent)'))->values()->all() : [],
        ];
    }
}
