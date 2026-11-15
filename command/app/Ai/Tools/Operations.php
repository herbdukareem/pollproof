<?php

namespace App\Ai\Tools;

use App\Models\CollationEntry;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;
use App\Models\VideoCheck;
use App\Services\Reconciler;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/** The day's work queues: verification, collation checks and the AI's video checks. */
class Operations extends PollProofTool
{
    protected string $name = 'work_queues';

    protected ?string $description = 'What is waiting for people: results waiting to be verified (and how long the oldest has waited), corrections, reviewer flags, collation sheets that differ from our units, and announcement videos the AI heard differently from the signed result.';

    protected function properties(): array
    {
        return [new ToolProperty('race', PropertyType::STRING, 'Narrow to one race (code or name). Empty for all.', false)];
    }

    public function __invoke(?string $race = null): array
    {
        $election = $race ? $this->race($race) : null;
        $results = fn () => ResultSubmission::where('campaign_id', $this->campaignId())->where('is_current', true)
            ->whereIn('polling_unit_id', $this->units()->select('polling_units.id'))
            ->when($election, fn ($q) => $q->where('election_id', $election->id));
        $oldest = $results()->where('status', ResultSubmission::PENDING_REVIEW)->oldest('created_at')->first();
        $reconciler = app(Reconciler::class);
        $collation = CollationEntry::where('campaign_id', $this->campaignId())->when($election, fn ($q) => $q->where('election_id', $election->id))
            ->with('election')->latest('id')->take(60)->get()
            ->map(fn ($e) => ['race' => $e->election?->name, 'comparison' => $reconciler->compareCollation($e)])
            ->filter(fn ($e) => ! empty($e['comparison']['differences']))->take(10)
            ->map(fn ($e) => ['race' => $e['race'], 'level' => $e['comparison']['level'], 'scope' => $e['comparison']['scope'], 'differences' => $e['comparison']['differences'], 'complete' => $e['comparison']['coverage_complete'] ?? null])->values();

        return [
            'race' => $election?->name ?? 'all races',
            'results_waiting_to_verify' => $results()->where('status', ResultSubmission::PENDING_REVIEW)->count(),
            'oldest_waiting_since' => $oldest?->created_at?->toIso8601String(),
            'results_sent_back' => $results()->where('status', ResultSubmission::SENT_BACK)->count(),
            'corrections_waiting' => $results()->where('status', ResultSubmission::PENDING_REVIEW)->whereNotNull('supersedes_id')->count(),
            'open_reviewer_flags' => ResultFlag::where('campaign_id', $this->campaignId())->where('status', ResultFlag::OPEN)->count(),
            'collation_sheets_that_differ' => $collation,
            'ai_video_checks' => VideoCheck::where('campaign_id', $this->campaignId())->when($election, fn ($q) => $q->where('election_id', $election->id))
                ->selectRaw("CONCAT(COALESCE(outcome, status), ':', review) k, count(*) n")->groupBy('k')->pluck('n', 'k'),
        ];
    }
}
