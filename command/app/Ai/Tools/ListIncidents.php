<?php

namespace App\Ai\Tools;

use App\Models\Incident;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class ListIncidents extends PollProofTool
{
    protected string $name = 'list_incidents';

    protected ?string $description = 'Incidents reported from the field (violence, vote buying, BVAS problems, agents turned away, late materials), newest first, with counts by type and status. Can narrow to one LGA.';

    protected function properties(): array
    {
        return [
            new ToolProperty('status', PropertyType::STRING, 'open, handling, resolved, or empty for all not resolved.', false),
            new ToolProperty('lga', PropertyType::STRING, 'Only this LGA.', false),
            new ToolProperty('limit', PropertyType::INTEGER, 'How many to list, up to 30. Default 10.', false),
        ];
    }

    public function __invoke(?string $status = null, ?string $lga = null, ?int $limit = 10): array
    {
        $l = $lga ? $this->lga($lga) : null;
        $q = fn () => Incident::where('campaign_id', $this->campaignId())
            ->whereIn('polling_unit_id', $this->units()->select('polling_units.id')->when($l, fn ($u) => $u->whereHas('ward', fn ($w) => $w->where('lga_id', $l->id))))
            ->when($status, fn ($i) => $i->where('status', $status), fn ($i) => $i->where('status', '!=', 'resolved'));

        return [
            'by_type' => $q()->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type'),
            'by_severity' => $q()->selectRaw('severity, count(*) n')->groupBy('severity')->pluck('n', 'severity'),
            'incidents' => $q()->with('pollingUnit.ward.lga', 'user:id,code')->latest('occurred_at')->take(min(30, max(1, $limit ?? 10)))->get()->map(fn (Incident $i) => [
                'type' => $i->type, 'severity' => $i->severity, 'status' => $i->status, 'at' => $i->occurred_at?->toIso8601String(),
                'unit' => $i->pollingUnit ? "{$i->pollingUnit->code}, {$i->pollingUnit->ward->name}, {$i->pollingUnit->ward->lga->name}" : null,
                'by' => $i->user?->code, 'description' => mb_substr((string) $i->description, 0, 240), 'assigned_to' => $i->assigned_to,
            ])->all(),
        ];
    }
}
