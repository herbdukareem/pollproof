<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OtpService;
use App\Services\SignInMonitor;
use App\Services\TwoFactor;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
        private readonly SignInMonitor $monitor,
        private readonly TwoFactor $twoFactor,
    ) {}

    /** Field staff ask for a one-time code by SMS. Always answers the same way, so it can't be used to probe numbers. */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = Phone::normalize($data['phone']) ?? $data['phone'];
        $user = User::where('phone', $phone)->first();
        if ($user && $user->isActive() && $user->role->isField()) {
            $this->otp->send($phone);
        }

        return response()->json(['message' => 'If this number is registered, a code has been sent.']);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $phone = Phone::normalize($data['phone']) ?? $data['phone'];
        $user = User::where('phone', $phone)->first();
        if (! $user || ! $user->isActive() || ! $user->role->isField() || ! $this->otp->verify($phone, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }
        $this->audit->log($user, 'auth.otp_login', null, [], $request->ip());

        return $this->tokenResponse($user, $data['device_name'] ?? 'agent-app');
    }

    /** Command-room staff sign in with email and password. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:20']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->isActive() || ! $user->password || ! Hash::check($data['password'], $user->password) || $user->role->isField()) {
            $this->monitor->failed($user, 'auth.failed_login', ['email' => $data['email']], $request->ip());
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }
        if ($user->hasTwoFactor()) {
            if (empty($data['code'])) {
                return response()->json(['message' => 'Enter the code from your authenticator app.', 'two_factor_required' => true, 'errors' => ['code' => ['Enter the code from your authenticator app.']]], 422);
            }
            if (! $this->twoFactor->check($user, $data['code'])) {
                $this->monitor->failed($user, 'auth.failed_two_factor', [], $request->ip());
                throw ValidationException::withMessages(['code' => 'That code is not right.']);
            }
        }
        $this->audit->log($user, 'auth.login', null, [], $request->ip());

        return $this->tokenResponse($user, 'command-room');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['campaign:id,name,state_name,election_date,require_two_factor', 'pollingUnit.ward.lga', 'ward.lga', 'lga']);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'code' => $user->code,
            'role' => $user->role,
            'role_label' => $user->role->label(),
            'is_reserve' => $user->is_reserve,
            'must_change_password' => $user->must_change_password,
            'two_factor_enabled' => $user->hasTwoFactor(),
            'two_factor_required' => $user->needsTwoFactorSetup(),
            'campaign' => $user->campaign,
            'polling_unit' => $user->pollingUnit ? [
                'id' => $user->pollingUnit->id,
                'code' => $user->pollingUnit->code,
                'name' => $user->pollingUnit->name,
                'latitude' => $user->pollingUnit->latitude,
                'longitude' => $user->pollingUnit->longitude,
                'ward' => $user->pollingUnit->ward->name,
                'lga' => $user->pollingUnit->ward->lga->name,
            ] : null,
            'ward' => $user->ward?->only(['id', 'code', 'name']),
            'lga' => ($user->lga ?? $user->ward?->lga)?->only(['id', 'code', 'name']),
            'parties' => $user->campaign?->parties()->get(['code', 'name', 'is_ours']),
        ]);
    }

    private function tokenResponse(User $user, string $name): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($name, [$user->role->value])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role, 'campaign_id' => $user->campaign_id],
        ]);
    }
}
