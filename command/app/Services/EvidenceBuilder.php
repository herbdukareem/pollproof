<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\CollationEntry;
use App\Models\Election;
use App\Models\Incident;
use App\Models\IrevEntry;
use App\Models\PollingUnit;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;

/**
 * Everything held about one polling unit in one race, as used in the evidence pack and in
 * tribunal bundles. Check-ins and incidents belong to the unit, not the race.
 */
final class EvidenceBuilder
{
    public function __construct(private readonly Reconciler $reconciler) {}

    public function build(PollingUnit $unit, ?Election $race = null): array
    {
        $unit->load('ward.lga');
        $races = Election::where('campaign_id', $unit->campaign_id)->with('areas')->orderBy('election_date')->orderBy('sort_order')->get()
            ->filter(fn (Election $e) => $e->coversUnit($unit))->values();
        // Without a race named: the unit's first race that has a result, else its first race.
        $withResults = ResultSubmission::where('polling_unit_id', $unit->id)->distinct()->pluck('election_id');
        $race ??= $races->first(fn ($e) => $withResults->contains($e->id)) ?? $races->first();
        $raceId = $race?->id ?? 0;

        $results = ResultSubmission::where('polling_unit_id', $unit->id)->where('election_id', $raceId)->with(['votes.party', 'user:id,name,code'])->orderBy('id')->get();
        $irev = IrevEntry::where('polling_unit_id', $unit->id)->where('election_id', $raceId)->latest('seen_at')->latest('id')->first();
        $collation = collect([
            [CollationEntry::WARD, $unit->ward_id],
            [CollationEntry::LGA, $unit->ward->lga_id],
            [CollationEntry::STATE, null],
        ])->map(fn ($s) => $race ? $this->reconciler->latestCollation($race, ...$s) : null)
            ->filter()
            ->map(fn ($e) => ['id' => $e->id, 'announced_at' => $e->announced_at, 'comparison' => $this->reconciler->compareCollation($e)])
            ->values();

        return [
            'generated_at' => now()->toIso8601String(),
            'election' => $race ? TallyService::race($race) : null,
            'races' => $races->map(fn (Election $e) => ['id' => $e->id, 'name' => $e->name, 'code' => $e->code, 'has_results' => $withResults->contains($e->id)])->values(),
            'polling_unit' => ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name, 'ward' => $unit->ward->name, 'lga' => $unit->ward->lga->name, 'latitude' => $unit->latitude, 'longitude' => $unit->longitude],
            'results' => $results->map(fn ($r) => [
                'id' => $r->id,
                'is_current' => $r->is_current,
                'supersedes_id' => $r->supersedes_id,
                'correction_reason' => $r->correction_reason,
                'agent' => $r->user?->only(['id', 'name', 'code']),
                'channel' => $r->channel,
                'captured_at' => $r->captured_at,
                'received_at' => $r->created_at,
                'latitude' => $r->latitude,
                'longitude' => $r->longitude,
                'accredited' => $r->accredited,
                'rejected' => $r->rejected,
                'total_cast' => $r->total_cast,
                'votes' => $r->votesByParty(),
                'flags' => $r->flags ?? [],
                'photo_sha256' => $r->photo_sha256,
                'has_photo' => $r->photo_path !== null,
                'payload_hash' => $r->payload_hash,
                'signature_valid' => $r->signature_valid,
                'review' => ['status' => $r->status, 'by' => $r->reviewed_by, 'at' => $r->reviewed_at, 'note' => $r->review_note],
            ]),
            'irev' => $irev ? ['seen_at' => $irev->seen_at, 'not_on_irev' => $irev->not_on_irev, 'votes' => $irev->votes, 'has_screenshot' => $irev->screenshot_path !== null, 'comparison' => $this->reconciler->compareIrev($irev)] : null,
            'check_ins' => CheckIn::where('polling_unit_id', $unit->id)->orderBy('id')->get(['id', 'user_id', 'channel', 'status', 'distance_m', 'accuracy_m', 'captured_at', 'reviewed_by']),
            'incidents' => Incident::where('polling_unit_id', $unit->id)->orderBy('occurred_at')->get(['id', 'type', 'severity', 'description', 'status', 'occurred_at']),
            'collation' => $collation,
            'flags' => ResultFlag::where('polling_unit_id', $unit->id)->whereIn('result_submission_id', $results->pluck('id'))->orderBy('id')->get()->map->present(),
        ];
    }
}
