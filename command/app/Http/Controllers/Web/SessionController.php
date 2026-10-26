<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SignInMonitor;
use App\Services\TwoFactor;
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
    private const PENDING = 'two_factor.pending';

    public function store(Request $request, AuditLogger $audit, SignInMonitor $monitor): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->isActive() || ! $user->password || $user->role->isField() || ! Hash::check($data['password'], $user->password)) {
            $monitor->failed($user, 'auth.failed_login', ['email' => $data['email'], 'via' => 'web'], $request->ip());
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }

        if ($user->hasTwoFactor()) {
            // Password is right; the session is only created after the second step.
            $request->session()->put(self::PENDING, ['id' => $user->id, 'expires' => now()->addMinutes(5)->timestamp, 'tries' => 0]);

            return response()->json(['two_factor' => true]);
        }

        return $this->complete($request, $user, $audit);
    }

    public function twoFactor(Request $request, TwoFactor $twoFactor, AuditLogger $audit, SignInMonitor $monitor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $pending = $request->session()->get(self::PENDING);
        if (! $pending || $pending['expires'] < now()->timestamp) {
            $request->session()->forget(self::PENDING);
            throw ValidationException::withMessages(['code' => 'That took too long. Sign in again.']);
        }
        $user = User::find($pending['id']);
        if (! $user || ! $user->isActive() || ! $twoFactor->check($user, $data['code'])) {
            $monitor->failed($user, 'auth.failed_two_factor', ['via' => 'web'], $request->ip());
            if (++$pending['tries'] >= 5) {
                $request->session()->forget(self::PENDING);
                throw ValidationException::withMessages(['code' => 'Too many wrong codes. Sign in again.']);
            }
            $request->session()->put(self::PENDING, $pending);
            throw ValidationException::withMessages(['code' => 'That code is not right. Use the newest code in your app, or a recovery code.']);
        }
        $request->session()->forget(self::PENDING);

        return $this->complete($request, $user, $audit, true);
    }

    private function complete(Request $request, User $user, AuditLogger $audit, bool $twoFactor = false): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $audit->log($user, 'auth.login', null, ['via' => 'web', 'two_factor' => $twoFactor], $request->ip());

        $user->forceFill(['last_active_at' => now()])->saveQuietly();

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role, 'must_change_password' => $user->must_change_password]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }
}
