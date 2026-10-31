<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PeopleMover;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Field people (agents, reserves, ward and LGA supervisors): find them and move them between posts. */
class PeopleController extends Controller
{
    public function __construct(private readonly PeopleMover $mover, private readonly AuditLogger $audit) {}

    /** Search by name, code or phone, for the "choose a person" boxes. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'role' => ['nullable', 'in:agent,ward_supervisor,lga_supervisor']]);
        $q = trim($data['search'] ?? '');
        $digits = preg_replace('/\D/', '', $q);
        $local = strlen($digits) >= 4 ? (Phone::normalize($q) ?? ltrim($digits, '0')) : null;

        $people = User::where('campaign_id', $user->campaign_id)
            ->whereIn('role', array_map(fn ($r) => $r->value, PeopleMover::MOVABLE))
            ->when($data['role'] ?? null, fn ($w, $r) => $w->where('role', $r))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->whereLike('name', "%{$q}%")->orWhereLike('code', "%{$q}%")
                ->when($local, fn ($y) => $y->orWhereLike('phone', "%{$local}%"))))
            ->with(['pollingUnit', 'ward.lga', 'lga'])
            ->orderByRaw('deactivated_at is not null')->orderBy('name')
            ->limit(12)->get();

        return response()->json(['data' => $people->map(fn (User $p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'phone' => $p->phone,
            'role' => $p->role->value,
            'role_label' => $p->role->label(),
            'post' => PeopleMover::post($p),
            'active' => $p->isActive(),
            'unit' => $p->pollingUnit?->code,
            'has_result' => PeopleMover::hasResult($p),
        ])]);
    }

    /** A supervisor or agent becomes an agent at a unit, or a reserve in a ward. */
    public function makeAgent(Request $request, User $person): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $campaign->abortIfFrozen();
        $data = $request->validate(['polling_unit_id' => ['nullable', 'integer'], 'ward_id' => ['nullable', 'integer']]);
        $unit = ($data['polling_unit_id'] ?? null) ? PollingUnit::where('campaign_id', $campaign->id)->with('ward')->findOrFail($data['polling_unit_id']) : null;
        $ward = ! $unit && ($data['ward_id'] ?? null) ? $campaign->wards()->findOrFail($data['ward_id']) : null;
        $before = PeopleMover::post($person);

        $result = $this->mover->toAgent($campaign, $person, $unit, $ward);
        $this->audit->log($request->user(), 'person.made_agent', $result['user'], ['from' => $before, 'polling_unit' => $unit?->code, 'reserve' => ! $unit, 'replaced_agent_id' => $result['replaced']?->id], $request->ip());

        return response()->json([
            'id' => $result['user']->id, 'code' => $result['user']->code, 'post' => PeopleMover::post($result['user']),
            'replaced' => $result['replaced'] ? ['code' => $result['replaced']->code, 'name' => $result['replaced']->name] : null,
        ]);
    }

    /** Take a supervisor off their post. */
    public function unassign(Request $request, User $person): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $campaign->abortIfFrozen();
        $before = PeopleMover::post($person);
        $this->mover->unassign($campaign, $person);
        $this->audit->log($request->user(), 'supervisor.removed', $person, ['from' => $before], $request->ip());

        return response()->json(['id' => $person->id, 'post' => PeopleMover::post($person->fresh())]);
    }
}
