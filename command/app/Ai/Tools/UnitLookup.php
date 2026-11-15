<?php

namespace App\Ai\Tools;

use App\Models\CheckIn;
use App\Models\Incident;
use App\Models\IrevEntry;
use App\Models\ResultFlag;
use App\Models\User;
use App\Models\VideoCheck;
use App\Models\VideoClip;
use App\Services\Races;
use App\Services\Reconciler;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class UnitLookup extends PollProofTool
{
    protected string $name = 'unit_lookup';

    protected ?string $description = "Everything recorded at one polling unit: its agent, today's check-in, each race's signed result (status, figures, channel), IReV, reviewer flags, incidents, videos and the AI's check of announcement videos. Use it to explain a unit's status or diagnose why it hasn't reported.";

    protected function properties(): array
    {
        return [new ToolProperty('unit', PropertyType::STRING, 'The unit code (e.g. 03-01-002) or part of its name.', true)];
    }

    public function __invoke(string $unit): array
    {
        $u = $this->units()->where('code', trim($unit))->first()
            ?? $this->units()->whereLike('name', '%'.trim($unit).'%')->first();
        if (! $u) {
            return $this->notFound('polling unit');
        }
        $u->load('ward.lga', 'agent');
        $checkIn = CheckIn::where('polling_unit_id', $u->id)->today()->latest('id')->first();
        $reconciler = app(Reconciler::class);

        return [
            'unit' => "{$u->code} {$u->name}", 'ward' => $u->ward->name, 'lga' => $u->ward->lga->name,
            'has_gps_point' => $u->latitude !== null,
            'agent' => $u->agent ? ['code' => $u->agent->code, 'name' => $u->agent->name, 'phone' => $this->phone($u->agent->phone), 'onboarding' => User::ONBOARDING_STEPS[$u->agent->onboardingStep()]] : null,
            'check_in_today' => $checkIn ? ['status' => $checkIn->status, 'counts' => $checkIn->counts(), 'distance_m' => $checkIn->distance_m, 'accuracy_m' => $checkIn->accuracy_m, 'channel' => $checkIn->channel, 'at' => $checkIn->captured_at?->toIso8601String()] : null,
            'races' => Races::atUnit($u)->map(function ($race) use ($u, $reconciler) {
                $r = $u->currentResultFor($race->id);
                $irev = IrevEntry::where('polling_unit_id', $u->id)->where('election_id', $race->id)->latest('seen_at')->first();

                return [
                    'race' => $race->name,
                    'result' => $r ? ['status' => $r->status, 'channel' => $r->channel, 'accredited' => $r->accredited, 'rejected' => $r->rejected, 'total_cast' => $r->total_cast,
                        'votes' => $r->votesByParty(), 'over_accreditation' => $r->total_cast > $r->accredited, 'signature_valid' => (bool) $r->signature_valid,
                        'has_photo' => $r->photo_path !== null, 'sent_back_note' => $r->review_note, 'captured_at' => $r->captured_at?->toIso8601String()] : null,
                    'irev' => $irev ? $reconciler->compareIrev($irev)['status'] : 'not checked',
                ];
            })->values()->all(),
            'open_reviewer_flags' => ResultFlag::where('polling_unit_id', $u->id)->where('status', ResultFlag::OPEN)->get()->map(fn ($f) => "{$f->label()}: {$f->note}")->all(),
            'incidents' => Incident::where('polling_unit_id', $u->id)->latest('occurred_at')->take(5)->get()->map(fn ($i) => "{$i->type} ({$i->severity}, {$i->status}): ".mb_substr($i->description, 0, 160))->all(),
            'videos' => VideoClip::where('polling_unit_id', $u->id)->whereNotNull('completed_at')->count(),
            'ai_video_checks' => VideoCheck::where('polling_unit_id', $u->id)->get()->map(fn ($c) => ['outcome' => $c->outcome, 'review' => $c->review, 'differences' => $c->differences])->all(),
        ];
    }
}
