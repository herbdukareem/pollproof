<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read models for the setup screens: structure, agent roster and supervisors. */
class SetupController extends Controller
{
    public function structure(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $data = $request->validate(['ward_id' => ['nullable', 'integer']]);
        $noGps = fn ($q) => $q->where(fn ($w) => $w->whereNull('latitude')->orWhereNull('longitude'));

        $lgas = $campaign->lgas()->orderBy('code')->with(['wards' => fn ($q) => $q->orderBy('code')
            ->withCount(['pollingUnits', 'pollingUnits as missing_gps_count' => $noGps])])->get();

        $units = [];
        if ($wardId = $data['ward_id'] ?? null) {
            $agents = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNotNull('polling_unit_id')->pluck('code', 'polling_unit_id');
            $units = PollingUnit::where('campaign_id', $campaign->id)->where('ward_id', $wardId)->orderBy('code')->get()
                ->map(fn ($u) => [
                    'id' => $u->id, 'code' => $u->code, 'name' => $u->name,
                    'latitude' => $u->latitude, 'longitude' => $u->longitude,
                    'registered_voters' => $u->registered_voters, 'agent' => $agents[$u->id] ?? null,
                ]);
        }

        $missing = PollingUnit::where('campaign_id', $campaign->id)->where($noGps)->with('ward.lga')->orderBy('code')->limit(100)->get()
            ->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'ward' => $u->ward->name, 'lga' => $u->ward->lga->name]);

        return response()->json([
            'campaign' => $campaign->only(['id', 'name', 'state_name', 'election_date', 'freeze_at', 'geofence_radius_m', 'max_gps_accuracy_m', 'require_selfie']),
            'frozen' => $campaign->isFrozen(),
            'totals' => [
                'lgas' => $lgas->count(),
                'wards' => $lgas->sum(fn ($l) => $l->wards->count()),
                'units' => $lgas->sum(fn ($l) => $l->wards->sum('polling_units_count')),
                'missing_gps' => $lgas->sum(fn ($l) => $l->wards->sum('missing_gps_count')),
            ],
            'lgas' => $lgas->map(fn ($l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name,
                'units' => $l->wards->sum('polling_units_count'),
                'missing_gps' => $l->wards->sum('missing_gps_count'),
                'wards' => $l->wards->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'units' => $w->polling_units_count, 'missing_gps' => $w->missing_gps_count]),
            ]),
            'units' => $units,
            'missing' => $missing,
        ]);
    }

    public function roster(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'filter' => ['nullable', 'in:all,incomplete,reserves,unassigned,unavailable'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'lga_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:latest,code,phone,unit,step'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $agents = User::where('campaign_id', $user->campaign_id)->where('role', Role::Agent)->whereNull('deactivated_at')
            ->withCount('devices')->with(['pollingUnit.ward.lga', 'ward.lga'])->orderBy('code')->get();
        $agents->each(fn (User $a) => $a->setAttribute('step', $a->onboardingStep()));
        $assigned = $agents->whereNotNull('polling_unit_id');

        $search = strtolower(trim($data['search'] ?? ''));
        $sort = $data['sort'] ?? 'latest';
        $descending = ($data['dir'] ?? ($sort === 'latest' ? 'desc' : 'asc')) === 'desc';
        $filtered = $agents
            ->filter(fn ($a) => match ($data['filter'] ?? 'all') {
                'incomplete' => $a->polling_unit_id && $a->step < 5,
                'reserves' => $a->is_reserve,
                'unassigned' => ! $a->polling_unit_id && ! $a->is_reserve,
                'unavailable' => $a->unavailable_at !== null,
                default => true,
            })
            ->filter(fn ($a) => empty($data['lga_id']) || (int) (($a->pollingUnit?->ward ?? $a->ward)?->lga_id ?? $a->lga_id) === (int) $data['lga_id'])
            ->filter(fn ($a) => $search === '' || str_contains(strtolower("{$a->code} {$a->name} {$a->phone} {$a->pollingUnit?->code}"), $search))
            // Newest agents first unless a column header asks otherwise; blanks always go last.
            ->sortBy(fn ($a) => match ($sort) {
                'code' => $a->code,
                'phone' => $a->phone,
                'unit' => $a->pollingUnit?->code,
                'step' => $a->step,
                default => $a->id,
            }, SORT_NATURAL, $descending)
            ->sortBy(fn ($a) => $sort === 'unit' && ! $a->polling_unit_id ? 1 : 0)
            ->values();

        $page = $data['page'] ?? 1;
        $perPage = (int) ($data['per_page'] ?? 50);
        $showPhones = $user->role->canSeePhones();

        return response()->json([
            'stages' => collect([1 => 'Invited', 2 => 'App installed', 3 => 'Practice sent', 4 => 'Trained', 5 => 'Confirmed'])
                ->map(fn ($label, $n) => ['label' => $label, 'value' => $assigned->where('step', '>=', $n)->count()])->values(),
            'counts' => [
                'assigned' => $assigned->count(),
                'reserves' => $agents->where('is_reserve', true)->count(),
                'unassigned' => $agents->filter(fn ($a) => ! $a->polling_unit_id && ! $a->is_reserve)->count(),
                'not_invited' => $assigned->where('step', 0)->count(),
                'unavailable' => $agents->whereNotNull('unavailable_at')->count(),
            ],
            'frozen' => $user->campaign->isFrozen(),
            'total' => $filtered->count(),
            'page' => $page,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($filtered->count() / $perPage)),
            'data' => $filtered->forPage($page, $perPage)->values()->map(function (User $a) use ($showPhones) {
                $ward = $a->pollingUnit?->ward ?? $a->ward;

                return [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'phone' => $showPhones ? $a->phone : Phone::masked($a->phone),
                    'polling_unit' => $a->pollingUnit?->only(['id', 'code', 'name']),
                    'place' => $ward ? "{$ward->name} · {$ward->lga->name}" : null,
                    'is_reserve' => $a->is_reserve,
                    'step' => $a->step,
                    'step_label' => User::ONBOARDING_STEPS[$a->step],
                    'quiz_score' => $a->quiz_score,
                    'unavailable' => $a->unavailable_at !== null,
                ];
            }),
        ]);
    }

    public function supervisors(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['lga_id' => ['nullable', 'integer']]);
        $campaign = $user->campaign;
        $showPhones = $user->role->canSeePhones();
        $person = fn (?User $u) => $u ? ['id' => $u->id, 'code' => $u->code, 'name' => $u->name, 'phone' => $showPhones ? $u->phone : Phone::masked($u->phone)] : null;

        $sups = User::where('campaign_id', $campaign->id)->whereIn('role', [Role::LgaSupervisor, Role::WardSupervisor])->whereNull('deactivated_at')->get();
        $lgaSups = $sups->where('role', Role::LgaSupervisor)->whereNotNull('lga_id');
        $wardSups = $sups->where('role', Role::WardSupervisor)->whereNotNull('ward_id')->keyBy('ward_id');
        $agentsByLga = User::where('users.campaign_id', $campaign->id)->where('users.role', Role::Agent)->whereNotNull('users.polling_unit_id')
            ->join('polling_units', 'polling_units.id', '=', 'users.polling_unit_id')
            ->join('wards', 'wards.id', '=', 'polling_units.ward_id')
            ->selectRaw('wards.lga_id, count(*) as n')->groupBy('wards.lga_id')->pluck('n', 'lga_id');

        $lgas = $campaign->lgas()->orderBy('code')->with(['wards' => fn ($q) => $q->orderBy('code')->withCount('pollingUnits')])->get();
        $rows = $lgas->map(fn ($l) => [
            'id' => $l->id,
            'name' => $l->name,
            'supervisor' => $person($lgaSups->where('lga_id', $l->id)->where('is_backup', false)->first()),
            'backup' => $person($lgaSups->where('lga_id', $l->id)->where('is_backup', true)->first()),
            'wards' => $l->wards->count(),
            'wards_covered' => $l->wards->filter(fn ($w) => $wardSups->has($w->id))->count(),
            'agents' => (int) ($agentsByLga[$l->id] ?? 0),
        ])->sortBy(fn ($r) => [$r['wards_covered'] === $r['wards'] && $r['supervisor'] ? 1 : 0, $r['name']])->values();

        $selected = $lgas->firstWhere('id', $data['lga_id'] ?? null) ?? $lgas->first(fn ($l) => $l->wards->contains(fn ($w) => ! $wardSups->has($w->id))) ?? $lgas->first();

        return response()->json([
            'frozen' => $campaign->isFrozen(),
            'levels' => [
                'lga_supervisors' => $lgaSups->where('is_backup', false)->count(),
                'lgas' => $lgas->count(),
                'ward_supervisors' => $wardSups->count(),
                'wards' => $lgas->sum(fn ($l) => $l->wards->count()),
                'agents' => (int) $agentsByLga->sum(),
                'reserves' => User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->where('is_reserve', true)->count(),
            ],
            'lgas' => $rows,
            'selected' => $selected ? [
                'id' => $selected->id,
                'name' => $selected->name,
                'wards' => $selected->wards->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'units' => $w->polling_units_count, 'supervisor' => $person($wardSups->get($w->id))]),
            ] : null,
        ]);
    }
}
