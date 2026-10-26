<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/** Records failed sign-ins and tells the DG when one account keeps failing. */
final class SignInMonitor
{
    public const THRESHOLD = 3;

    public function __construct(private readonly AuditLogger $audit, private readonly AlertService $alerts) {}

    public function failed(?User $user, string $action, array $meta, ?string $ip): void
    {
        $this->audit->log($user, $action, null, $meta, $ip, $user?->campaign_id);
        if (! $user?->campaign_id) {
            return;
        }
        $recent = AuditLog::where('campaign_id', $user->campaign_id)->where('user_id', $user->id)
            ->whereIn('action', ['auth.failed_login', 'auth.failed_two_factor'])
            ->where('created_at', '>=', now()->subMinutes(15))->count();
        if ($recent >= self::THRESHOLD) {
            $this->alerts->raise($user->campaign_id, 'failed_logins', 'high', "failed_logins:{$user->id}",
                "{$recent} failed sign-ins for {$user->name}",
                "Someone tried to sign in as {$user->email} and failed {$recent} times in 15 minutes, last from {$ip}. If it wasn't them, reset their password.",
                $user, ['window_start' => now()->subMinutes(15)->startOfMinute()->toIso8601String()]);
        }
    }
}
