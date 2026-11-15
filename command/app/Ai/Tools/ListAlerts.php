<?php

namespace App\Ai\Tools;

use App\Models\Alert;
use App\Models\NotificationRule;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class ListAlerts extends PollProofTool
{
    protected string $name = 'list_alerts';

    protected ?string $description = 'Alerts in the command room (overvotes, collation and IReV differences, absent agents, incidents, flagged results, AI video mismatches), newest first, with counts by type.';

    protected function properties(): array
    {
        return [
            new ToolProperty('status', PropertyType::STRING, 'open (default), resolved or all.', false, ['open', 'resolved', 'all']),
            new ToolProperty('type', PropertyType::STRING, 'Only this type: '.implode(', ', array_keys(NotificationRule::DEFAULTS)).'.', false),
            new ToolProperty('limit', PropertyType::INTEGER, 'How many to list, up to 30. Default 10.', false),
        ];
    }

    public function __invoke(?string $status = 'open', ?string $type = null, ?int $limit = 10): array
    {
        $q = fn () => Alert::where('campaign_id', $this->campaignId())
            ->when(($status ?? 'open') === 'open', fn ($a) => $a->where('status', '!=', 'resolved'))
            ->when($status === 'resolved', fn ($a) => $a->where('status', 'resolved'));

        return [
            'counts_by_type' => $q()->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type'),
            'alerts' => $q()->when($type, fn ($a) => $a->where('type', $type))->latest('id')->take(min(30, max(1, $limit ?? 10)))->get()
                ->map(fn (Alert $a) => ['type' => $a->type, 'severity' => $a->severity, 'title' => $a->title, 'detail' => $a->detail, 'status' => $a->status, 'opened' => ($a->opened_at ?? $a->created_at)?->toIso8601String()])->all(),
        ];
    }
}
