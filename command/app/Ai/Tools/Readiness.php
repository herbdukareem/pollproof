<?php

namespace App\Ai\Tools;

use App\Services\ReadinessService;

class Readiness extends PollProofTool
{
    protected string $name = 'readiness';

    protected ?string $description = 'How ready the campaign is before election day: agents assigned, app installed, practice result sent, trained, confirmed; gaps (units without an agent or GPS, wards without a supervisor, reserves); readiness by LGA; the setup freeze date and days to the election.';

    public function __invoke(): array
    {
        $r = app(ReadinessService::class)->report($this->user->campaign);

        return [
            'units' => $r['units'], 'funnel' => collect($r['funnel'])->pluck('value', 'label'), 'gaps' => $r['gaps'],
            'lgas_not_ready' => collect($r['lgas'])->where('status', '!=', 'ready')->map(fn ($l) => "{$l['name']}: {$l['confirmed']}% confirmed ({$l['status']})")->values()->take(20),
            'freeze_at' => $r['freeze_at'], 'election_date' => $r['election_date'],
            'days_to_election' => now()->startOfDay()->diffInDays($r['election_date'], false),
        ];
    }
}
