<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\AlertService;
use App\Services\AuditLogger;
use App\Services\IncidentReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public const TYPES = ['disruption', 'vote_buying', 'bvas_problem', 'agent_turned_away', 'materials_late', 'other'];

    public function __construct(private readonly AuditLogger $audit, private readonly AlertService $alerts, private readonly IncidentReporter $reporter) {}

    public function store(Request $request): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'severity' => ['required', 'in:low,medium,high'],
            'description' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'occurred_at' => ['required', 'date'],
            'media' => ['nullable', 'array', 'max:5'],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,audio/mpeg,audio/mp4,audio/aac,audio/ogg', 'max:20480'],
            'client_ref' => ['nullable', 'uuid'],
        ]);
        if (($data['client_ref'] ?? null) && ($seen = Incident::where('user_id', $user->id)->where('client_ref', $data['client_ref'])->first())) {
            return response()->json(['id' => $seen->id, 'status' => $seen->status]);
        }
        $paths = collect($request->file('media', []))
            ->map(fn ($f) => $f->store("campaigns/{$user->campaign_id}/incidents", config('pollproof.evidence_disk')))
            ->all();

        $incident = $this->reporter->report($user, $data['type'], $data['severity'], $data['description'], $data['occurred_at'],
            $data['latitude'] ?? null, $data['longitude'] ?? null, $paths, $data['client_ref'] ?? null);
        $this->audit->log($user, 'incident.reported', $incident, ['type' => $incident->type, 'severity' => $incident->severity], $request->ip());

        return response()->json(['id' => $incident->id, 'status' => $incident->status], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,handling,resolved'],
            'type' => ['nullable', 'in:'.implode(',', self::TYPES)],
            'severity' => ['nullable', 'in:high,medium,low'],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $scope = fn (array $skip = []) => Incident::where('campaign_id', $request->user()->campaign_id)
            ->when(! in_array('status', $skip) ? $data['status'] ?? null : null, fn ($q, $s) => $q->where('status', $s))
            ->when(! in_array('type', $skip) ? $data['type'] ?? null : null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('description', "%{$s}%")
                ->orWhereHas('pollingUnit', fn ($u) => $u->whereLike('code', "%{$s}%")->orWhereLike('name', "%{$s}%"))));
        $incidents = $scope()
            ->with(['pollingUnit:id,code,name', 'user:id,name,code'])
            ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->latest('occurred_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        // Counts for the filter chips: each counted with the other filters applied.
        $incidents = $incidents->toArray() + ['counts' => [
            'types' => $scope(['type'])->selectRaw('type, COUNT(*) c')->groupBy('type')->pluck('c', 'type'),
            'status' => $scope(['status'])->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status'),
        ]];

        return response()->json($incidents);
    }

    public function update(Request $request, Incident $incident): JsonResponse
    {
        $user = $request->user();
        abort_unless($incident->campaign_id === $user->campaign_id, 404);
        $data = $request->validate([
            'status' => ['nullable', 'in:open,handling,resolved'],
            'assigned_to' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $updates = $incident->updates ?? [];
        if ($data['note'] ?? null) {
            $updates[] = ['at' => now()->toIso8601String(), 'by' => $user->name, 'text' => $data['note']];
        }
        $incident->update(array_filter([
            'status' => $data['status'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
        ]) + ['updates' => $updates]);
        if ($incident->status === 'resolved') {
            $this->alerts->clear($incident->campaign_id, "incident:{$incident->id}");
        }
        $this->audit->log($user, 'incident.updated', $incident, $data, $request->ip());

        return response()->json($incident->fresh());
    }
}
