<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:dg,situation_room') */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(fn ($r) => Role::from($r), $roles);
        abort_unless($user && $user->campaign_id && $user->hasRole(...$allowed), 403, 'Your role cannot do this.');

        return $next($request);
    }
}
