<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentListController extends Controller
{
    /** Polling-unit agents and reserves with their election-day status. */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'status' => ['nullable', 'in:present,absent,outside_area,reserve,deactivated'],
            'lga_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        $agents = User::where('campaign_id', $user->campaign_id)
            ->where('role', Role::Agent)
            ->when($user->hasRole(Role::WardSupervisor), fn ($q) => $q->where(fn ($w) => $w->where('ward_id', $user->ward_id)->orWhere('is_reserve', true)))
            ->when($user->hasRole(Role::LgaSupervisor), fn ($q) => $q->where(fn ($w) => $w->where('lga_id', $user->lga_id)->orWhereHas('ward', fn ($x) => $x->where('lga_id', $user->lga_id))->orWhere('is_reserve', true)))
            ->with(['pollingUnit.ward.lga', 'ward.lga'])
            ->orderBy('code')
            ->get();

        $checkIns = CheckIn::where('campaign_id', $user->campaign_id)->whereIn('user_id', $agents->pluck('id'))
            ->orderBy('id')->get()->groupBy('user_id')->map->last();
        $results = ResultSubmission::where('campaign_id', $user->campaign_id)->where('is_current', true)
            ->whereIn('user_id', $agents->pluck('id'))->get()->keyBy('user_id');
        $showPhones = $user->role->canSeePhones();

        $rows = $agents->map(function (User $a) use ($checkIns, $results, $showPhones) {
            $c = $checkIns->get($a->id);
            $status = match (true) {
                ! $a->isActive() => 'deactivated',
                $a->is_reserve || ! $a->polling_unit_id => 'reserve',
                $c && $c->counts() => 'present',
                $c && $c->status === CheckIn::PENDING_REVIEW => 'outside_area',
                default => 'absent',
            };

            return [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'phone' => $showPhones ? $a->phone : null,
                'status' => $status,
                'active' => $a->isActive(),
                'lga_id' => $a->pollingUnit?->ward->lga_id ?? $a->ward?->lga_id ?? $a->lga_id,
                'area' => $a->pollingUnit ? null : (trim(($a->ward?->name ?? '').($a->ward?->lga ? ' · '.$a->ward->lga->name : ''), ' ·') ?: null),
                'polling_unit' => $a->pollingUnit ? ['id' => $a->pollingUnit->id, 'code' => $a->pollingUnit->code, 'ward' => $a->pollingUnit->ward->name, 'lga' => $a->pollingUnit->ward->lga->name] : null,
                'check_in' => $c ? ['id' => $c->id, 'status' => $c->status, 'distance_m' => $c->distance_m, 'at' => $c->captured_at] : null,
                'result' => ($r = $results->get($a->id)) ? ['id' => $r->id, 'status' => $r->status, 'at' => $r->captured_at] : null,
            ];
        });

        // The summary tiles count every agent; the list follows the LGA and search too.
        $search = strtolower(trim($data['search'] ?? ''));
        $list = $rows
            ->when($data['lga_id'] ?? null, fn ($c, $l) => $c->where('lga_id', (int) $l))
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains(strtolower("{$r['code']} {$r['name']} {$r['phone']} ".($r['polling_unit']['code'] ?? '').' '.($r['polling_unit']['ward'] ?? '')), $search)));

        return response()->json([
            'summary' => $rows->countBy('status'),
            'matching' => $list->countBy('status'),
            'can_edit' => $user->hasRole(Role::SituationRoom, Role::Dg),
            'data' => ($data['status'] ?? null) ? $list->where('status', $data['status'])->values() : $list->where('status', '!=', 'deactivated')->values(),
        ]);
    }
}
