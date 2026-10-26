<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\CollationEntry;
use App\Models\Incident;
use App\Models\IrevEntry;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Services\AuditLogger;
use App\Services\Reconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    /** Units with their reporting status, most urgent first. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'lga_id' => ['nullable', 'integer'],
            'ward_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:reported,awaiting,no_agent,flagged'],
            'search' => ['nullable', 'string', 'max:50'],
        ]);

        $units = PollingUnit::where('polling_units.campaign_id', $user->campaign_id)
            ->with(['ward.lga', 'currentResult', 'agent:id,name,code,polling_unit_id'])
            ->when($data['ward_id'] ?? null, fn ($q, $w) => $q->where('ward_id', $w))
            ->when($data['lga_id'] ?? null, fn ($q, $l) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $l)))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%")))
            ->when(($data['status'] ?? null) === 'reported', fn ($q) => $q->whereHas('currentResult'))
            ->when(($data['status'] ?? null) === 'awaiting', fn ($q) => $q->whereDoesntHave('currentResult'))
            ->when(($data['status'] ?? null) === 'no_agent', fn ($q) => $q->whereDoesntHave('agent'))
            ->when(($data['status'] ?? null) === 'flagged', fn ($q) => $q->whereHas('currentResult', fn ($r) => $r->whereRaw("flags::text LIKE '%overvote%'")))
            ->orderBy('code')
            ->paginate(100);

        $checkedIn = CheckIn::where('campaign_id', $user->campaign_id)
            ->whereIn('polling_unit_id', $units->pluck('id'))
            ->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED])
            ->pluck('polling_unit_id')->flip();
        $showPhones = $user->role->canSeePhones();

        $units->getCollection()->transform(fn (PollingUnit $u) => [
            'id' => $u->id,
            'code' => $u->code,
            'name' => $u->name,
            'ward' => $u->ward->name,
            'lga' => $u->ward->lga->name,
            'has_gps' => $u->latitude !== null,
            'agent' => $u->agent ? ['id' => $u->agent->id, 'code' => $u->agent->code, 'name' => $u->agent->name] + ($showPhones ? ['phone' => $u->agent->phone] : []) : null,
            'agent_checked_in' => $checkedIn->has($u->id),
            'result' => $u->currentResult ? [
                'id' => $u->currentResult->id,
                'status' => $u->currentResult->status,
                'total_cast' => $u->currentResult->total_cast,
                'flags' => $u->currentResult->flags ?? [],
                'captured_at' => $u->currentResult->captured_at,
            ] : null,
        ]);

        return response()->json($units);
    }

    /**
     * Everything the legal team needs for one unit: our signed record and its chain of corrections,
     * the IReV comparison, the check-in, incidents and the collation sheets that cover it.
     */
    public function evidence(Request $request, PollingUnit $unit, Reconciler $reconciler, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($unit->campaign_id === $user->campaign_id, 404);
        $unit->load('ward.lga');

        $results = ResultSubmission::where('polling_unit_id', $unit->id)->with(['votes.party', 'user:id,name,code'])->orderBy('id')->get();
        $irev = IrevEntry::where('polling_unit_id', $unit->id)->latest('seen_at')->latest('id')->first();
        $collation = collect([
            [CollationEntry::WARD, $unit->ward_id],
            [CollationEntry::LGA, $unit->ward->lga_id],
            [CollationEntry::STATE, null],
        ])->map(fn ($s) => $reconciler->latestCollation($unit->campaign_id, ...$s))
            ->filter()
            ->map(fn ($e) => ['id' => $e->id, 'announced_at' => $e->announced_at, 'comparison' => $reconciler->compareCollation($e)])
            ->values();

        $audit->log($user, 'evidence.viewed', $unit, [], $request->ip());

        return response()->json([
            'generated_at' => now()->toIso8601String(),
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
            'irev' => $irev ? ['seen_at' => $irev->seen_at, 'not_on_irev' => $irev->not_on_irev, 'votes' => $irev->votes, 'has_screenshot' => $irev->screenshot_path !== null, 'comparison' => $reconciler->compareIrev($irev)] : null,
            'check_ins' => CheckIn::where('polling_unit_id', $unit->id)->orderBy('id')->get(['id', 'user_id', 'status', 'distance_m', 'accuracy_m', 'captured_at', 'reviewed_by']),
            'incidents' => Incident::where('polling_unit_id', $unit->id)->orderBy('occurred_at')->get(['id', 'type', 'severity', 'description', 'status', 'occurred_at']),
            'collation' => $collation,
        ]);
    }
}
