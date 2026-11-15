<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Incident;
use App\Models\VideoClip;
use App\Services\AuditLogger;
use App\Services\Races;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Videos from field staff, sent in pieces so a weak or dropped connection never starts over.
 *
 *   POST videos                 describe the clip; the reply says how many bytes are already in
 *   POST videos/{id}/chunk      the next piece, as the raw request body, with Upload-Offset
 *
 * Both are safe to repeat: describing the same clip again (same client_id) finds the upload in
 * progress, and a piece sent from the wrong offset is refused with the offset to send from.
 */
class VideoClipController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(array_keys(VideoClip::KINDS))],
            'mime' => ['required', 'in:video/mp4,video/3gpp,video/quicktime,video/webm,video/x-matroska'],
            'size' => ['required', 'integer', 'min:1', 'max:'.VideoClip::MAX_BYTES],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'duration_s' => ['nullable', 'integer', 'min:0', 'max:7200'],
            'captured_at' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:500'],
            'incident_id' => ['nullable', 'integer'],
            'election' => ['nullable', 'string', 'max:12'],
        ], ['size.max' => 'This video is too long to send. Record a shorter one.']);

        $clip = VideoClip::where('user_id', $user->id)->where('client_id', $data['client_id'])->first();
        if (! $clip) {
            $incident = ($data['incident_id'] ?? null)
                ? Incident::where('campaign_id', $user->campaign_id)->where('user_id', $user->id)->find($data['incident_id'])
                : null;
            $unit = $user->pollingUnit;
            $ward = $unit?->ward ?? $user->ward;
            $race = ($data['election'] ?? null)
                ? Election::where('campaign_id', $user->campaign_id)->where('code', strtoupper($data['election']))->first()
                : ($unit && $data['kind'] === 'result' ? Races::atUnit($unit)->first() : null);
            $clip = VideoClip::create([
                'campaign_id' => $user->campaign_id,
                'user_id' => $user->id,
                'client_id' => $data['client_id'],
                'kind' => $data['kind'],
                'election_id' => $race?->id,
                'polling_unit_id' => $unit?->id ?? $incident?->polling_unit_id,
                'ward_id' => $ward?->id,
                'lga_id' => $ward?->lga_id ?? $user->lga_id,
                'incident_id' => $incident?->id,
                'note' => ($n = trim((string) ($data['note'] ?? ''))) !== '' ? $n : null,
                'mime' => $data['mime'],
                'size' => $data['size'],
                'sha256' => $data['sha256'],
                'duration_s' => $data['duration_s'] ?? null,
                'captured_at' => $data['captured_at'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);
            $this->audit->log($user, 'video.started', $clip, ['kind' => $clip->kind, 'size' => $clip->size], $request->ip());
        }

        return response()->json($this->progress($clip), $clip->wasRecentlyCreated ? 201 : 200);
    }

    public function chunk(Request $request, VideoClip $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 404);
        if ($video->isComplete()) {
            return response()->json($this->progress($video));
        }
        $offset = $request->header('Upload-Offset');
        if (! is_numeric($offset) || (int) $offset !== (int) $video->received) {
            // Sent from the wrong place (a piece lost on the way, or sent twice): say where to go on from.
            return response()->json(['message' => 'Send from the offset given.'] + $this->progress($video), Response::HTTP_CONFLICT);
        }
        $bytes = $request->getContent();
        if ($bytes === '' || strlen($bytes) > VideoClip::CHUNK_BYTES * 2 || $video->received + strlen($bytes) > $video->size) {
            return response()->json(['message' => 'That piece is the wrong size.'] + $this->progress($video), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $local = Storage::disk('local');
        $part = $video->partPath();
        $local->makeDirectory(dirname($part));
        file_put_contents($local->path($part), $bytes, FILE_APPEND | LOCK_EX);
        clearstatcache(true, $local->path($part));
        $video->update(['received' => filesize($local->path($part))]);

        if ($video->received >= $video->size) {
            $this->finish($video, $request);
        }

        return response()->json($this->progress($video->fresh()));
    }

    /** The command room's list: newest first, with filters for the Videos page. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(VideoClip::KINDS))],
            'lga_id' => ['nullable', 'integer'],
            'ward_id' => ['nullable', 'integer'],
            'polling_unit_id' => ['nullable', 'integer'],
            'incident_id' => ['nullable', 'integer'],
            'election' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:6', 'max:100'],
        ]);
        $scope = fn (array $skip = []) => VideoClip::where('campaign_id', $user->campaign_id)->whereNotNull('completed_at')
            // Supervisors see their own area, as everywhere else.
            ->when($user->role === Role::WardSupervisor, fn ($q) => $q->where('ward_id', $user->ward_id))
            ->when($user->role === Role::LgaSupervisor, fn ($q) => $q->where('lga_id', $user->lga_id))
            ->when(! in_array('kind', $skip) ? $data['kind'] ?? null : null, fn ($q, $v) => $q->where('kind', $v))
            ->when($data['lga_id'] ?? null, fn ($q, $v) => $q->where('lga_id', $v))
            ->when($data['ward_id'] ?? null, fn ($q, $v) => $q->where('ward_id', $v))
            ->when($data['polling_unit_id'] ?? null, fn ($q, $v) => $q->where('polling_unit_id', $v))
            ->when($data['incident_id'] ?? null, fn ($q, $v) => $q->where('incident_id', $v))
            ->when($data['election'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('election_id', $v)->orWhereNull('election_id')))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('note', "%{$s}%")
                ->orWhereHas('pollingUnit', fn ($u) => $u->whereLike('code', "%{$s}%")->orWhereLike('name', "%{$s}%"))
                ->orWhereHas('user', fn ($u) => $u->whereLike('code', "%{$s}%")->orWhereLike('name', "%{$s}%"))));

        $page = $scope()->with(['user', 'pollingUnit', 'ward', 'lga', 'incident', 'election'])
            ->latest('captured_at')->latest('id')
            ->paginate((int) ($data['per_page'] ?? 24));
        $page->getCollection()->transform(fn (VideoClip $v) => $v->present());

        return response()->json($page->toArray() + [
            'counts' => ['kinds' => $scope(['kind'])->selectRaw('kind, COUNT(*) c')->groupBy('kind')->pluck('c', 'kind')],
            'kinds' => VideoClip::KINDS,
        ]);
    }

    /** The video itself. On a local disk the browser can seek (range requests). */
    public function file(Request $request, VideoClip $video): Response
    {
        $user = $request->user();
        abort_unless($video->campaign_id === $user->campaign_id && $video->isComplete(), 404);
        abort_if($user->role === Role::WardSupervisor && $video->ward_id !== $user->ward_id, 404);
        abort_if($user->role === Role::LgaSupervisor && $video->lga_id !== $user->lga_id, 404);
        // A player asks for the clip again each time it seeks, and a list shows a frame of each
        // (?thumb=1): log the first request of a real viewing only.
        if (! $request->boolean('thumb') && (! $request->headers->has('Range') || str_starts_with((string) $request->header('Range'), 'bytes=0-'))) {
            $this->audit->log($user, 'video.viewed', $video, [], $request->ip());
        }
        $disk = Storage::disk(config('pollproof.evidence_disk'));
        $name = "video-{$video->id}.{$video->extension()}";
        try {
            return response()->file($disk->path($video->path), ['Content-Type' => $video->mime, 'Content-Disposition' => "inline; filename=\"{$name}\""]);
        } catch (\Throwable) {
            return $disk->response($video->path, $name, ['Content-Type' => $video->mime]);
        }
    }

    /** All bytes are in: check them against the hash the phone sent, then file the video as evidence. */
    private function finish(VideoClip $video, Request $request): void
    {
        $local = Storage::disk('local');
        $part = $local->path($video->partPath());
        if ($video->received !== $video->size || hash_file('sha256', $part) !== $video->sha256) {
            // Damaged on the way: start again from nothing rather than keep a video that isn't the one recorded.
            @unlink($part);
            $video->update(['received' => 0]);
            $this->audit->log($request->user(), 'video.damaged', $video, [], $request->ip());

            return;
        }
        $path = "campaigns/{$video->campaign_id}/videos/{$video->id}-{$video->sha256}.{$video->extension()}";
        $stream = fopen($part, 'rb');
        Storage::disk(config('pollproof.evidence_disk'))->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($part);
        $video->update(['path' => $path, 'completed_at' => now()]);
        $this->audit->log($request->user(), 'video.received', $video, ['size' => $video->size, 'sha256' => $video->sha256], $request->ip());
    }

    /** @return array{id: int, received: int, size: int, complete: bool, chunk_bytes: int} */
    private function progress(VideoClip $video): array
    {
        return [
            'id' => $video->id,
            'received' => (int) $video->received,
            'size' => (int) $video->size,
            'complete' => $video->isComplete(),
            'chunk_bytes' => VideoClip::CHUNK_BYTES,
        ];
    }
}
