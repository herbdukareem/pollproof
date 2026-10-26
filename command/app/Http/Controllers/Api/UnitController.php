<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\PollingUnit;
use App\Services\AuditLogger;
use App\Services\EvidenceBuilder;
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
    public function evidence(Request $request, PollingUnit $unit, EvidenceBuilder $evidence, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($unit->campaign_id === $user->campaign_id, 404);
        $pack = $evidence->build($unit);
        $audit->log($user, 'evidence.viewed', $unit, [], $request->ip());

        return response()->json($pack);
    }
}
