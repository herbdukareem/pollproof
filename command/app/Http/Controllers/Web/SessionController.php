<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cookie sign-in for the command-room web app. After this, the same /api/v1 endpoints the
 * phone app uses are reachable from the browser through Sanctum's stateful session.
 */
class SessionController extends Controller
{
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->password || $user->role->isField() || ! Hash::check($data['password'], $user->password)) {
            $audit->log($user, 'auth.failed_login', null, ['email' => $data['email'], 'via' => 'web'], $request->ip(), $user?->campaign_id);
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $audit->log($user, 'auth.login', null, ['via' => 'web'], $request->ip());

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }
}
