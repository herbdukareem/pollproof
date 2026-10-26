<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\PollingUnit;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Collection;

/** How ready the field team is: from every unit having an agent to every agent confirmed for election day. */
final class ReadinessService
{
    /** @return Collection<int, User> agents holding a polling unit, with devices_count and their unit's LGA */
    public function assignedAgents(Campaign $campaign): Collection
    {
        return User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNull('deactivated_at')
            ->whereNotNull('polling_unit_id')->withCount('devices')->with('pollingUnit.ward:id,lga_id')->get();
    }

    public function report(Campaign $campaign): array
    {
        $units = PollingUnit::where('campaign_id', $campaign->id)->with('ward:id,lga_id')->get(['id', 'ward_id', 'latitude', 'longitude']);
        $agents = $this->assignedAgents($campaign);
        $steps = $agents->mapWithKeys(fn (User $a) => [$a->id => $a->onboardingStep()]);
        $reached = fn (int $n, ?Collection $subset = null) => ($subset ?? $agents)->filter(fn ($a) => $steps[$a->id] >= $n)->count();

        $total = $units->count();
        $funnel = [
            ['key' => 'assigned', 'label' => 'Agent assigned', 'value' => $agents->count()],
            ['key' => 'installed', 'label' => 'App installed', 'value' => $reached(2)],
            ['key' => 'tested', 'label' => 'Practice result sent', 'value' => $reached(3)],
            ['key' => 'trained', 'label' => 'Trained', 'value' => $reached(4)],
            ['key' => 'confirmed', 'label' => 'Confirmed for election day', 'value' => $reached(5)],
        ];

        $byLga = $agents->groupBy(fn ($a) => $a->pollingUnit->ward->lga_id);
        $unitsByLga = $units->groupBy(fn ($u) => $u->ward->lga_id);
        $lgas = $campaign->lgas()->orderBy('code')->get()->map(function ($lga) use ($byLga, $unitsByLga, $reached) {
            $unitCount = $unitsByLga->get($lga->id, collect())->count();
            $subset = $byLga->get($lga->id, collect());
            $pct = fn (int $n) => $unitCount ? round(100 * $n / $unitCount) : 0;
            $confirmed = $pct($reached(5, $subset));

            return [
                'id' => $lga->id,
                'name' => $lga->name,
                'units' => $unitCount,
                'assigned' => $pct($subset->count()),
                'tested' => $pct($reached(3, $subset)),
                'trained' => $pct($reached(4, $subset)),
                'confirmed' => $confirmed,
                'status' => $confirmed >= 95 ? 'ready' : ($confirmed >= 85 ? 'at_risk' : 'not_ready'),
            ];
        })->sortBy('confirmed')->values();

        $wardsWithout = Ward::where('campaign_id', $campaign->id)
            ->whereNotIn('id', User::where('campaign_id', $campaign->id)->where('role', Role::WardSupervisor)->whereNotNull('ward_id')->select('ward_id'))
            ->count();
        $reserves = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNull('deactivated_at')
            ->where('is_reserve', true)->whereNull('polling_unit_id')->get();

        return [
            'units' => $total,
            'funnel' => $funnel,
            'lgas' => $lgas,
            'gaps' => [
                'units_without_agent' => $total - $agents->count(),
                'not_installed' => $agents->count() - $reached(2),
                'not_trained' => $agents->filter(fn ($a) => $steps[$a->id] >= 2 && $steps[$a->id] < 4)->count(),
                'not_confirmed' => $agents->filter(fn ($a) => $steps[$a->id] < 5)->count(),
                'unavailable' => $agents->whereNotNull('unavailable_at')->count(),
                'reserves_free' => $reserves->count(),
                'reserves_trained' => $reserves->filter(fn ($r) => $r->trained_at)->count(),
                'units_without_gps' => $units->filter(fn ($u) => $u->latitude === null || $u->longitude === null)->count(),
                'wards_without_supervisor' => $wardsWithout,
            ],
            'freeze_at' => $campaign->freeze_at?->toDateString(),
            'election_date' => $campaign->election_date->toDateString(),
        ];
    }

    /** Agents in a reminder group, for SMS. */
    public function group(Campaign $campaign, string $group): Collection
    {
        $agents = $this->assignedAgents($campaign);

        return match ($group) {
            'not_installed' => $agents->filter(fn ($a) => $a->onboardingStep() < 2),
            'not_trained' => $agents->filter(fn ($a) => $a->onboardingStep() >= 2 && $a->onboardingStep() < 4),
            'not_confirmed' => $agents->filter(fn ($a) => $a->onboardingStep() < 5 && $a->unavailable_at === null),
        };
    }
}
