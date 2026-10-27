<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated accounts lose access at once, whether they use a token or a browser session.
 * Staff with a temporary password, or without two-step sign-in where the campaign requires it,
 * can only reach their own account endpoints until they fix that. Also records when people were last active.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            abort(403, 'This account has been deactivated.');
        }
        if ($user && ($user->must_change_password || $user->needsTwoFactorSetup()) && ! $this->accountRoute($request)) {
            abort(403, $user->must_change_password ? 'Change your temporary password first.' : 'Set up two-step sign-in first.');
        }
        if ($user && (! $user->last_active_at || $user->last_active_at->lt(now()->subMinutes(5)))) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }

    private function accountRoute(Request $request): bool
    {
        return $request->is('api/v1/me', 'api/v1/me/*', 'api/v1/auth/logout');
    }
}
