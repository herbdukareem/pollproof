<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,acknowledged,resolved'],
            'type' => ['nullable', 'string', 'max:40'],
        ]);
        $alerts = Alert::where('campaign_id', $request->user()->campaign_id)
            ->where('status', $data['status'] ?? 'open')
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
            ->latest('updated_at')
            ->paginate(50);

        return response()->json($alerts);
    }

    public function update(Request $request, Alert $alert, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($alert->campaign_id === $user->campaign_id, 404);
        $data = $request->validate(['status' => ['required', 'in:acknowledged,resolved']]);
        $alert->update([
            'status' => $data['status'],
            'resolved_by' => $data['status'] === 'resolved' ? $user->id : null,
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
        ]);
        $audit->log($user, 'alert.'.$data['status'], $alert, [], $request->ip());

        return response()->json($alert);
    }
}
