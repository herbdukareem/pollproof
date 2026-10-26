<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\AlertService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public const TYPES = ['disruption', 'vote_buying', 'bvas_problem', 'agent_turned_away', 'materials_late', 'other'];

    public function __construct(private readonly AuditLogger $audit, private readonly AlertService $alerts) {}

    public function store(Request $request): JsonResponse
    {
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
        ]);
        $paths = collect($request->file('media', []))
            ->map(fn ($f) => $f->store("campaigns/{$user->campaign_id}/incidents", config('pollproof.evidence_disk')))
            ->all();

        $incident = Incident::create([
            'campaign_id' => $user->campaign_id,
            'user_id' => $user->id,
            'polling_unit_id' => $user->polling_unit_id,
            'type' => $data['type'],
            'severity' => $data['severity'],
            'description' => $data['description'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'media_paths' => $paths,
            'occurred_at' => $data['occurred_at'],
            'updates' => [],
        ]);
        if ($incident->severity === 'high') {
            $where = $user->pollingUnit?->code ?? 'Unassigned agent';
            $this->alerts->raise($user->campaign_id, 'incident', 'high', "incident:{$incident->id}",
                "{$where}: ".str_replace('_', ' ', $incident->type), $incident->description, $incident);
        }
        $this->audit->log($user, 'incident.reported', $incident, ['type' => $incident->type, 'severity' => $incident->severity], $request->ip());

        return response()->json(['id' => $incident->id, 'status' => $incident->status], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:open,handling,resolved'], 'type' => ['nullable', 'in:'.implode(',', self::TYPES)]]);
        $incidents = Incident::where('campaign_id', $request->user()->campaign_id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->with(['pollingUnit:id,code,name', 'user:id,name,code'])
            ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->latest('occurred_at')
            ->paginate(50);

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
