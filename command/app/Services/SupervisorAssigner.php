<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lga;
use App\Models\User;
use App\Models\Ward;

final class SupervisorAssigner
{
    /**
     * Put a person in an LGA or ward supervisor post, creating them if new.
     *
     * @return array{user: User, replaced: ?User}|string the error message when the phone can't be used
     */
    public function assign(Campaign $campaign, string $name, string $phone, string $level, Lga $lga, ?Ward $ward, bool $backup = false): array|string
    {
        $role = $level === 'lga' ? Role::LgaSupervisor : Role::WardSupervisor;
        $user = User::where('phone', $phone)->first();
        if ($user && $user->campaign_id !== $campaign->id) {
            return 'Phone is registered to another campaign';
        }
        if ($user && ! $user->hasRole(Role::LgaSupervisor, Role::WardSupervisor)) {
            return 'Phone belongs to a '.$user->role->label();
        }

        $holder = User::where('campaign_id', $campaign->id)->where('role', $role)
            ->when($level === 'lga', fn ($q) => $q->where('lga_id', $lga->id)->where('is_backup', $backup))
            ->when($level === 'ward', fn ($q) => $q->where('ward_id', $ward->id))
            ->when($user, fn ($q) => $q->where('id', '!=', $user->id))
            ->first();
        $holder?->update(['lga_id' => null, 'ward_id' => null, 'is_backup' => false]);

        $attrs = ['name' => $name, 'role' => $role, 'lga_id' => $lga->id, 'ward_id' => $ward?->id, 'is_backup' => $level === 'lga' && $backup];
        if ($user) {
            $user->update($attrs);
        } else {
            $prefix = $level === 'lga' ? 'SV' : 'WS';
            $max = User::where('campaign_id', $campaign->id)->where('code', 'like', "{$prefix}-%")->pluck('code')
                ->map(fn ($c) => (int) preg_replace('/\D/', '', $c))->max() ?? 0;
            $user = User::create($attrs + ['campaign_id' => $campaign->id, 'phone' => $phone, 'code' => sprintf('%s-%03d', $prefix, $max + 1)]);
        }

        return ['user' => $user, 'replaced' => $holder];
    }
}
