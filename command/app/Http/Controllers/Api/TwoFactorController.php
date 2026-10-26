<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** A staff member setting up, checking or turning off their own two-step sign-in. */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasTwoFactor(),
            'pending' => ! $user->hasTwoFactor() && $user->two_factor_secret !== null,
            'required' => (bool) $user->campaign?->require_two_factor,
            'recovery_codes_left' => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : 0,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->role->isField(), 403, 'Field staff sign in with a code sent by SMS.');
        $this->checkPassword($request);

        return response()->json($this->twoFactor->start($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        if (! $codes) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and use the newest code.']);
        }
        $this->audit->log($request->user(), 'auth.two_factor_enabled', null, [], $request->ip());

        return response()->json(['enabled' => true, 'recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasTwoFactor(), 422, 'Two-step sign-in is not on.');
        $this->checkPassword($request);
        $this->audit->log($request->user(), 'auth.two_factor_recovery_codes', null, [], $request->ip());

        return response()->json(['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($request->user())]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->campaign?->require_two_factor, 422, 'Your campaign requires two-step sign-in, so it cannot be turned off.');
        $this->checkPassword($request);
        $this->twoFactor->disable($user);
        $this->audit->log($user, 'auth.two_factor_disabled', null, [], $request->ip());

        return response()->json(['enabled' => false]);
    }

    private function checkPassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is not right.']);
        }
    }
}
