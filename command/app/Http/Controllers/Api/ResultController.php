<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\ResultSubmission;
use App\Services\AuditLogger;
use App\Services\ResultRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultController extends Controller
{
    public function __construct(private readonly ResultRecorder $recorder, private readonly AuditLogger $audit) {}

    /** An agent submits the signed EC8A result for their unit. */
    public function store(Request $request): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        $unit = $user->pollingUnit;
        abort_unless($unit, 422, 'You are not assigned to a polling unit.');

        $data = $request->validate([
            'device_id' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:200'],
            'captured_at' => ['required', 'string', 'max:40'],
            'accredited' => ['required', 'integer', 'min:0'],
            'rejected' => ['required', 'integer', 'min:0'],
            'votes' => ['required', 'array', 'min:1'],
            'votes.*' => ['integer', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo_sha256' => ['nullable', 'string', 'size:64'],
            'photo' => ['nullable', 'image', 'max:10240'],
            'supersedes_id' => ['nullable', 'integer'],
            'correction_reason' => ['required_with:supersedes_id', 'nullable', 'string', 'max:255'],
        ]);

        $device = Device::where('id', $data['device_id'])->where('user_id', $user->id)->first();
        abort_unless($device, 422, 'This device is not registered to you.');

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $actual = hash_file('sha256', $request->file('photo')->getRealPath());
            abort_unless(($data['photo_sha256'] ?? null) === $actual, 422, 'The photo does not match the signed photo fingerprint.');
            $photoPath = $request->file('photo')->store("campaigns/{$user->campaign_id}/ec8a", config('pollproof.evidence_disk'));
        }

        $result = $this->recorder->record(
            agent: $user,
            unit: $unit,
            votes: array_change_key_case($data['votes'], CASE_UPPER),
            accredited: (int) $data['accredited'],
            rejected: (int) $data['rejected'],
            capturedAt: $data['captured_at'],
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            photoPath: $photoPath,
            photoSha256: $data['photo_sha256'] ?? null,
            device: $device,
            signature: $data['signature'],
            supersedesId: $data['supersedes_id'] ?? null,
            correctionReason: $data['correction_reason'] ?? null,
        );
        $this->audit->log($user, $result->supersedes_id ? 'result.corrected' : 'result.submitted', $result, ['payload_hash' => $result->payload_hash], $request->ip());

        return response()->json($this->present($result), 201);
    }

    /** Upload the EC8A photo later, e.g. after the figures went by SMS. The photo must match the signed fingerprint. */
    public function attachPhoto(Request $request, ResultSubmission $result): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        abort_unless($result->user_id === $user->id, 404);
        $request->validate(['photo' => ['required', 'image', 'max:10240']]);
        $actual = hash_file('sha256', $request->file('photo')->getRealPath());
        if ($result->photo_sha256) {
            abort_unless($result->photo_sha256 === $actual, 422, 'The photo does not match the signed photo fingerprint.');
        }
        $result->update([
            'photo_path' => $request->file('photo')->store("campaigns/{$user->campaign_id}/ec8a", config('pollproof.evidence_disk')),
            'photo_sha256' => $result->photo_sha256 ?? $actual,
        ]);
        $this->audit->log($user, 'result.photo_attached', $result, ['sha256' => $actual], $request->ip());

        return response()->json($this->present($result));
    }

    /** The EC8A photo, streamed only to signed-in staff of the same campaign. */
    public function photo(Request $request, ResultSubmission $result): StreamedResponse
    {
        abort_unless($result->campaign_id === $request->user()->campaign_id && $result->photo_path, 404);
        $this->audit->log($request->user(), 'result.photo_viewed', $result, [], $request->ip());

        return Storage::disk(config('pollproof.evidence_disk'))->response($result->photo_path);
    }

    /** Results waiting for a second person to check them against the photo, oldest first; flagged ones first. */
    public function queue(Request $request): JsonResponse
    {
        $items = ResultSubmission::where('campaign_id', $request->user()->campaign_id)
            ->where('is_current', true)
            ->where('status', ResultSubmission::PENDING_REVIEW)
            ->with(['pollingUnit.ward.lga', 'votes.party', 'user:id,name,code'])
            ->orderByRaw("CASE WHEN flags::text LIKE '%overvote%' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->present($r))]);
    }

    public function show(Request $request, ResultSubmission $result): JsonResponse
    {
        abort_unless($result->campaign_id === $request->user()->campaign_id, 404);

        return response()->json($this->present($result->load(['pollingUnit.ward.lga', 'votes.party', 'user:id,name,code'])));
    }

    public function approve(Request $request, ResultSubmission $result): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();

        return $this->review($request, $result, ResultSubmission::APPROVED);
    }

    public function sendBack(Request $request, ResultSubmission $result): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();

        return $this->review($request, $result, ResultSubmission::SENT_BACK);
    }

    private function review(Request $request, ResultSubmission $result, string $status): JsonResponse
    {
        $user = $request->user();
        abort_unless($result->campaign_id === $user->campaign_id, 404);
        abort_if($result->user_id === $user->id, 403, 'You cannot review your own result.');
        $data = $request->validate(['note' => [$status === ResultSubmission::SENT_BACK ? 'required' : 'nullable', 'nullable', 'string', 'max:255']]);
        $result->update(['status' => $status, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);
        $this->audit->log($user, 'result.'.$status, $result, ['note' => $data['note'] ?? null], $request->ip());

        return response()->json($this->present($result));
    }

    private function present(ResultSubmission $r): array
    {
        $r->loadMissing(['votes.party', 'pollingUnit.ward.lga']);

        return [
            'id' => $r->id,
            'polling_unit' => ['id' => $r->pollingUnit->id, 'code' => $r->pollingUnit->code, 'name' => $r->pollingUnit->name, 'ward' => $r->pollingUnit->ward->name, 'lga' => $r->pollingUnit->ward->lga->name],
            'agent' => $r->relationLoaded('user') ? $r->user?->only(['id', 'name', 'code']) : null,
            'channel' => $r->channel,
            'accredited' => $r->accredited,
            'rejected' => $r->rejected,
            'total_valid' => $r->total_valid,
            'total_cast' => $r->total_cast,
            'votes' => $r->votesByParty(),
            'flags' => $r->flags ?? [],
            'status' => $r->status,
            'is_current' => $r->is_current,
            'supersedes_id' => $r->supersedes_id,
            'correction_reason' => $r->correction_reason,
            'signature_valid' => $r->signature_valid,
            'payload_hash' => $r->payload_hash,
            'fingerprint' => $r->fingerprint(),
            'has_photo' => $r->photo_path !== null,
            'captured_at' => $r->captured_at,
            'received_at' => $r->created_at,
            'review_note' => $r->review_note,
        ];
    }
}
