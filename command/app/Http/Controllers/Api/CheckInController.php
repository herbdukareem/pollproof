<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Services\AlertService;
use App\Services\AuditLogger;
use App\Services\CheckInReview;
use App\Services\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CheckInController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly AlertService $alerts, private readonly CheckInReview $reviews) {}

    public function store(Request $request): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        $unit = $user->pollingUnit;
        abort_unless($unit, 422, 'You are not assigned to a polling unit.');

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['required', 'integer', 'min:0', 'max:5000'],
            'captured_at' => ['required', 'date'],
            'selfie' => [$user->campaign->require_selfie ? 'required' : 'nullable', 'image', 'max:5120'],
            'request_review' => ['nullable', 'boolean'],
            'client_ref' => ['nullable', 'uuid'],
        ]);
        if (($data['client_ref'] ?? null) && ($seen = CheckIn::where('user_id', $user->id)->where('client_ref', $data['client_ref'])->first())) {
            return response()->json(['id' => $seen->id, 'status' => $seen->status, 'distance_m' => $seen->distance_m]);
        }

        $campaign = $user->campaign;
        // Agents check in once per working day. Already in today: say so, don't add another.
        $sameDay = CheckIn::where('user_id', $user->id)->where('polling_unit_id', $unit->id)
            ->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED, CheckIn::PENDING_REVIEW])
            ->onDay($data['captured_at'])->latest('id')->first();
        if ($sameDay?->counts()) {
            return response()->json([
                'id' => $sameDay->id, 'status' => $sameDay->status, 'distance_m' => $sameDay->distance_m, 'already' => true,
                'message' => "You are already checked in at {$unit->code} today.",
            ]);
        }

        $distance = ($unit->latitude !== null && $unit->longitude !== null)
            ? Geo::distanceMeters($data['latitude'], $data['longitude'], $unit->latitude, $unit->longitude)
            : null;
        $inside = $distance !== null
            && $distance <= $campaign->geofence_radius_m
            && $data['accuracy_m'] <= $campaign->max_gps_accuracy_m;

        if (! $inside && ! ($data['request_review'] ?? false)) {
            return response()->json([
                'status' => 'outside_area',
                'distance_m' => $distance,
                'accuracy_m' => $data['accuracy_m'],
                'radius_m' => $campaign->geofence_radius_m,
                'message' => $distance === null
                    ? 'This unit has no GPS point yet. Ask your supervisor to confirm your check-in.'
                    : "You're {$distance} m from {$unit->code}. Move closer or ask your supervisor to confirm.",
            ], 422);
        }

        $selfie = $request->file('selfie')?->store("campaigns/{$campaign->id}/selfies", config('pollproof.evidence_disk'));
        $checkIn = CheckIn::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'polling_unit_id' => $unit->id,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy_m' => $data['accuracy_m'],
            'distance_m' => $distance,
            'status' => $inside ? CheckIn::VERIFIED : CheckIn::PENDING_REVIEW,
            'selfie_path' => $selfie,
            'captured_at' => $data['captured_at'],
            'client_ref' => $data['client_ref'] ?? null,
        ]);
        // Checking in again while the first one waits for review replaces it, so the supervisor
        // sees one card per agent: the newest location, or none if the agent is now inside.
        if ($sameDay) {
            $sameDay->update(['status' => CheckIn::REPLACED]);
        }
        $this->audit->log($user, 'checkin.created', $checkIn, ['status' => $checkIn->status, 'distance_m' => $distance, 'replaces' => $sameDay?->id], $request->ip());
        if ($checkIn->counts()) {
            $this->alerts->clear($campaign->id, "agent_absent:{$unit->id}");
            $this->reviews->settled($checkIn);
        } else {
            $this->reviews->waiting($checkIn);
        }

        return response()->json(['id' => $checkIn->id, 'status' => $checkIn->status, 'distance_m' => $distance], 201);
    }

    /** Check-ins waiting for a supervisor, limited to the supervisor's own ward or LGA. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = CheckIn::where('check_ins.campaign_id', $user->campaign_id)
            ->where('status', CheckIn::PENDING_REVIEW)
            ->when($user->hasRole(Role::WardSupervisor), fn ($q) => $q->whereHas('pollingUnit', fn ($u) => $u->where('ward_id', $user->ward_id)))
            ->when($user->hasRole(Role::LgaSupervisor), fn ($q) => $q->whereHas('pollingUnit.ward', fn ($w) => $w->where('lga_id', $user->lga_id)))
            ->with(['pollingUnit:id,code,name,ward_id', 'user:id,name,code,phone'])
            ->oldest('captured_at')
            ->get();

        $showPhones = $user->role->canSeePhones();

        return response()->json(['data' => $items->map(fn ($c) => [
            'id' => $c->id,
            'agent' => $c->user->only(['id', 'name', 'code']) + ['phone' => $showPhones ? $c->user->phone : null],
            'polling_unit' => $c->pollingUnit->only(['id', 'code', 'name']),
            'channel' => $c->channel,
            'distance_m' => $c->distance_m,
            'accuracy_m' => $c->accuracy_m,
            'has_selfie' => $c->selfie_path !== null,
            'captured_at' => $c->captured_at,
        ])]);
    }

    /** Supervisors approve or reject check-ins made outside the unit area. */
    public function review(Request $request, CheckIn $checkIn): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        abort_unless($checkIn->campaign_id === $user->campaign_id, 404);
        $unit = $checkIn->pollingUnit()->with('ward')->first();
        abort_if($user->hasRole(Role::WardSupervisor) && $unit->ward_id !== $user->ward_id, 403, 'This unit is not in your ward.');
        abort_if($user->hasRole(Role::LgaSupervisor) && $unit->ward->lga_id !== $user->lga_id, 403, 'This unit is not in your LGA.');

        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        abort_unless($checkIn->status === CheckIn::PENDING_REVIEW, 422, $checkIn->status === CheckIn::REPLACED
            ? 'The agent checked in again since. Refresh to see the new check-in.'
            : 'This check-in has already been reviewed.');
        $checkIn->update([
            'status' => $data['decision'] === 'approve' ? CheckIn::APPROVED : CheckIn::REJECTED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
        $this->audit->log($user, 'checkin.'.$data['decision'], $checkIn, [], $request->ip());
        $this->reviews->settled($checkIn);
        if ($checkIn->counts()) {
            $this->alerts->clear($checkIn->campaign_id, "agent_absent:{$unit->id}");
        }

        return response()->json(['id' => $checkIn->id, 'status' => $checkIn->status]);
    }

    /** Approve or reject several check-ins at once. Ones already handled, or outside the supervisor's area, are skipped. */
    public function bulkReview(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->campaign->abortIfDeclared();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $items = CheckIn::where('check_ins.campaign_id', $user->campaign_id)->whereIn('id', $data['ids'])->where('status', CheckIn::PENDING_REVIEW)
            ->when($user->hasRole(Role::WardSupervisor), fn ($q) => $q->whereHas('pollingUnit', fn ($u) => $u->where('ward_id', $user->ward_id)))
            ->when($user->hasRole(Role::LgaSupervisor), fn ($q) => $q->whereHas('pollingUnit.ward', fn ($w) => $w->where('lga_id', $user->lga_id)))
            ->get();
        foreach ($items as $checkIn) {
            $checkIn->update(['status' => $data['decision'] === 'approve' ? CheckIn::APPROVED : CheckIn::REJECTED, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            $this->audit->log($user, 'checkin.'.$data['decision'], $checkIn, ['bulk' => true], $request->ip());
            $this->reviews->settled($checkIn);
            if ($checkIn->counts()) {
                $this->alerts->clear($checkIn->campaign_id, "agent_absent:{$checkIn->polling_unit_id}");
            }
        }

        return response()->json(['done' => $items->count(), 'skipped' => count($data['ids']) - $items->count()]);
    }

    /** The selfie sent with a check-in, for the supervisor deciding whether to approve it. */
    public function selfie(Request $request, CheckIn $checkIn): StreamedResponse
    {
        $user = $request->user();
        abort_unless($checkIn->campaign_id === $user->campaign_id && $checkIn->selfie_path, 404);
        $unit = $checkIn->pollingUnit()->with('ward')->first();
        abort_if($user->hasRole(Role::WardSupervisor) && $unit->ward_id !== $user->ward_id, 403);
        abort_if($user->hasRole(Role::LgaSupervisor) && $unit->ward->lga_id !== $user->lga_id, 403);

        return Storage::disk(config('pollproof.evidence_disk'))->response($checkIn->selfie_path);
    }
}
