<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;

/** Records an agent's answer to "will you be at your unit on election day?", from the app or by SMS reply. */
final class AttendanceConfirmer
{
    public function __construct(private readonly AlertService $alerts, private readonly AuditLogger $audit) {}

    /** @return array{confirmed: bool, confirmed_at: mixed, unavailable_at: mixed} */
    public function confirm(User $user, bool $available, ?string $note = null, ?string $ip = null): array
    {
        $user->update($available ? ['confirmed_at' => now(), 'unavailable_at' => null] : ['unavailable_at' => now()]);
        $unit = $user->pollingUnit;
        $key = "agent_unavailable:{$user->id}";
        if (! $available && $user->hasRole(Role::Agent) && $unit) {
            // A NO tells the situation room and supervisors to send a reserve to this unit.
            $this->alerts->raise($user->campaign_id, 'agent_unavailable', 'medium', $key,
                "{$unit->code}: agent can't make election day",
                ($note ? "{$user->code} says: {$note}. " : "{$user->code} said no. ").'Send a reserve.',
                $unit, ['said_no_at' => now()->toIso8601String()]);
        } elseif ($available) {
            $this->alerts->clear($user->campaign_id, $key);
        }
        $this->audit->log($user, $available ? 'onboarding.confirmed' : 'onboarding.unavailable', null, ['note' => $note], $ip);

        return ['confirmed' => $available, 'confirmed_at' => $user->confirmed_at, 'unavailable_at' => $user->unavailable_at];
    }
}
