<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lga;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves field people between posts: an agent becomes a ward or LGA supervisor, a supervisor goes back
 * to being an agent (at a unit or as a reserve), or someone leaves a post. Whoever held the post they
 * move into is either left unassigned or made a reserve agent, as the caller chooses.
 *
 * A person whose role changes gets a code for the new role (AG-, WS-, SV-) and is signed out of the
 * app, so it opens the right screens next time. Their earlier records (check-ins, results) stay theirs.
 */
final class PeopleMover
{
    public const MOVABLE = [Role::Agent, Role::WardSupervisor, Role::LgaSupervisor];

    /**
     * @param  'unassigned'|'reserve'  $holderTo  what happens to the person who held this post
     * @return array{user: User, replaced: ?User, freed_unit: ?PollingUnit}
     */
    public function toSupervisor(Campaign $campaign, User $person, string $level, Lga $lga, ?Ward $ward, bool $backup = false, string $holderTo = 'unassigned'): array
    {
        $this->check($campaign, $person);
        $role = $level === 'lga' ? Role::LgaSupervisor : Role::WardSupervisor;

        return DB::transaction(function () use ($campaign, $person, $role, $level, $lga, $ward, $backup, $holderTo) {
            $holder = User::where('campaign_id', $campaign->id)->where('role', $role)->whereNull('deactivated_at')
                ->when($level === 'lga', fn ($q) => $q->where('lga_id', $lga->id)->where('is_backup', $backup))
                ->when($level === 'ward', fn ($q) => $q->where('ward_id', $ward->id))
                ->where('id', '!=', $person->id)
                ->first();
            if ($holder) {
                $holderTo === 'reserve'
                    ? $this->becomeAgent($campaign, $holder, null, $ward ?? $holder->ward)
                    : $holder->update(['lga_id' => null, 'ward_id' => null, 'is_backup' => false]);
            }

            $freed = $person->hasRole(Role::Agent) ? $person->pollingUnit : null;
            $this->changeRole($campaign, $person, $role, [
                'lga_id' => $lga->id, 'ward_id' => $ward?->id, 'is_backup' => $level === 'lga' && $backup,
                'polling_unit_id' => null, 'is_reserve' => false,
            ]);

            return ['user' => $person->fresh(), 'replaced' => $holder?->fresh(), 'freed_unit' => $freed];
        });
    }

    /**
     * Make someone an agent at a unit, or a reserve in a ward when no unit is given.
     * The unit's current agent, if any, becomes a reserve in the same ward.
     *
     * @return array{user: User, replaced: ?User}
     */
    public function toAgent(Campaign $campaign, User $person, ?PollingUnit $unit, ?Ward $ward = null): array
    {
        $this->check($campaign, $person);

        return DB::transaction(function () use ($campaign, $person, $unit, $ward) {
            $previous = $unit ? User::where('polling_unit_id', $unit->id)->where('role', Role::Agent)->where('id', '!=', $person->id)->first() : null;
            $previous?->update(['polling_unit_id' => null, 'is_reserve' => true]);
            $this->becomeAgent($campaign, $person, $unit, $unit?->ward ?? $ward);

            return ['user' => $person->fresh(), 'replaced' => $previous?->fresh()];
        });
    }

    /** Register a new field person with no post yet, ready to be moved into one. */
    public function create(Campaign $campaign, string $name, string $phone, Role $role): User
    {
        return User::create(['campaign_id' => $campaign->id, 'name' => $name, 'phone' => $phone, 'role' => $role, 'code' => $this->nextCode($campaign, $role)]);
    }

    /** Take a supervisor off their post; they stay registered and can be given another one. */
    public function unassign(Campaign $campaign, User $person): void
    {
        $this->check($campaign, $person);
        abort_unless($person->hasRole(Role::WardSupervisor, Role::LgaSupervisor), 422, 'Only supervisors leave a post this way. Release an agent from the Agents page.');
        $person->update(['lga_id' => null, 'ward_id' => null, 'is_backup' => false]);
    }

    /** A short description of where someone is now, e.g. "Agent at 01-01-001" or "Ward supervisor · Boku, Agaie". */
    public static function post(User $u): string
    {
        $u->loadMissing(['pollingUnit', 'ward.lga', 'lga']);

        return match (true) {
            $u->deactivated_at !== null => 'Deactivated',
            $u->hasRole(Role::Agent) && $u->pollingUnit !== null => "Agent at {$u->pollingUnit->code}",
            $u->hasRole(Role::Agent) && $u->is_reserve => 'Reserve agent'.($u->ward ? " · {$u->ward->name}" : ''),
            $u->hasRole(Role::Agent) => 'Agent, not assigned',
            $u->hasRole(Role::WardSupervisor) && $u->ward !== null => "Ward supervisor · {$u->ward->name}, {$u->ward->lga->name}",
            $u->hasRole(Role::LgaSupervisor) && $u->lga !== null => 'LGA supervisor · '.$u->lga->name.($u->is_backup ? ' (backup)' : ''),
            $u->hasRole(Role::WardSupervisor, Role::LgaSupervisor) => $u->role->label().', no post',
            default => $u->role->label(),
        };
    }

    private function becomeAgent(Campaign $campaign, User $person, ?PollingUnit $unit, ?Ward $ward): void
    {
        $this->changeRole($campaign, $person, Role::Agent, [
            'polling_unit_id' => $unit?->id, 'is_reserve' => $unit === null,
            'ward_id' => $ward?->id, 'lga_id' => $ward?->lga_id, 'is_backup' => false,
        ]);
    }

    private function changeRole(Campaign $campaign, User $person, Role $role, array $attrs): void
    {
        $changing = $person->role !== $role;
        if ($changing) {
            $attrs += ['role' => $role, 'code' => $this->nextCode($campaign, $role)];
        }
        $person->update($attrs);
        if ($changing) {
            $person->tokens()->delete(); // sign in again to get the screens for the new role
        }
    }

    private function nextCode(Campaign $campaign, Role $role): string
    {
        // The same formats the roster and supervisor imports use.
        [$prefix, $digits] = match ($role) {
            Role::LgaSupervisor => ['SV', 3],
            Role::WardSupervisor => ['WS', 3],
            default => ['AG', 4],
        };
        $numbers = User::where('campaign_id', $campaign->id)->where('code', 'like', "{$prefix}-%")->pluck('code')
            ->map(fn ($c) => preg_replace('/\D/', '', $c))->filter();
        // Keep the width the campaign already uses (AG-01644 → AG-01645).
        $digits = max($digits, $numbers->map(fn ($n) => strlen($n))->max() ?? 0);

        return sprintf("%s-%0{$digits}d", $prefix, ((int) $numbers->map(fn ($n) => (int) $n)->max()) + 1);
    }

    private function check(Campaign $campaign, User $person): void
    {
        abort_unless($person->campaign_id === $campaign->id, 404);
        if (! $person->hasRole(...self::MOVABLE)) {
            throw ValidationException::withMessages(['user_id' => "{$person->name} is {$person->role->label()} staff, not a field agent or supervisor."]);
        }
        if (! $person->isActive()) {
            throw ValidationException::withMessages(['user_id' => "{$person->name} is deactivated. Reactivate them first."]);
        }
    }

    /** Whether an agent already has a result for their unit, which a move leaves on record. */
    public static function hasResult(User $u): bool
    {
        return $u->hasRole(Role::Agent) && ResultSubmission::where('user_id', $u->id)->exists();
    }
}
