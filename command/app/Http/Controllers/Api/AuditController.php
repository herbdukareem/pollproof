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
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        return response()->json(AuditLog::where('campaign_id', $request->user()->campaign_id)
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($data['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('action', "%{$s}%")->orWhere('ip', $s)
                ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$s}%")->orWhereLike('code', "%{$s}%"))))
            ->with('user:id,name,code,role')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 50)));
    }

    public function verify(Request $request, AuditLogger $audit): JsonResponse
    {
        return response()->json($audit->verify($request->user()->campaign_id));
    }
}
