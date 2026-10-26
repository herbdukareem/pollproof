<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['nullable', 'string', 'max:60'], 'user_id' => ['nullable', 'integer']]);

        return response()->json(AuditLog::where('campaign_id', $request->user()->campaign_id)
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($data['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->orderByDesc('id')
            ->paginate(100));
    }

    public function verify(Request $request, AuditLogger $audit): JsonResponse
    {
        return response()->json($audit->verify($request->user()->campaign_id));
    }
}
