<?php

namespace App\Ai\Tools;

use App\Services\TallyService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class RaceStandings extends PollProofTool
{
    protected string $name = 'race_standings';

    protected ?string $description = 'How we stand: with no race, every race (margin, share, units reported, waiting to verify). With a race, its full tally: each party, the winning rule (25% spread for governor), and each LGA or ward with our share and who leads.';

    protected function properties(): array
    {
        return [new ToolProperty('race', PropertyType::STRING, 'A race code (GOV, HOA-BIDA1) or name. Leave empty for every race.', false)];
    }

    public function __invoke(?string $race = null): array
    {
        $tally = app(TallyService::class);
        if (! $race) {
            return ['races' => collect($tally->board($this->user->campaign))->map(fn ($r) => [
                'race' => $r['name'], 'code' => $r['code'], 'area' => $r['area'], 'date' => $r['election_date'], 'declared' => (bool) $r['declared_at'],
                'standing' => $r['standing'], 'margin' => $r['margin'], 'our_share' => $r['ours']['share'] ?? null,
                'top' => collect($r['candidates'])->map(fn ($c) => "{$c['name']} {$c['votes']}")->all(),
                'units_reported' => "{$r['units_reported']} of {$r['units']}", 'waiting_to_verify' => $r['waiting'], 'spread' => $r['spread'] ?? null,
            ])->values()->all()];
        }
        $election = $this->race($race);
        if (! $election) {
            return $this->notFound('race');
        }
        $s = $tally->summary($election);
        $ours = collect($s['candidates'])->firstWhere('is_ours', true);

        return [
            'race' => $election->name, 'rule' => $s['rule'], 'area_level' => $s['area_level'],
            'units_reported' => "{$s['units_reported']} of {$s['units']}",
            'candidates' => collect($s['candidates'])->map(fn ($c) => ['party' => $c['party'], 'name' => $c['name'], 'candidate' => $c['candidate'], 'votes' => $c['votes'], 'share' => $c['share'], 'ours' => $c['is_ours'], 'leads' => $c['leads'], 'areas_with_25_percent' => $c['lgas_meeting_threshold']])->all(),
            'spread_needed' => $s['rule'] === 'spread' ? "{$s['lgas_required']} of {$s['lga_count']} LGAs at 25%" : null,
            'areas' => collect($s['lgas'])->map(fn ($l) => [
                'name' => $l['name'], 'units_reported' => "{$l['units_reported']} of {$l['units']}",
                'our_share' => $ours ? ($l['share'][$ours['party']] ?? 0) : null,
                'leader' => collect($l['votes'])->sortDesc()->keys()->first(),
            ])->values()->all(),
        ];
    }
}
