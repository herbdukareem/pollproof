<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp, private readonly AuditLogger $audit) {}

    /** Field staff ask for a one-time code by SMS. Always answers the same way, so it can't be used to probe numbers. */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $user = User::where('phone', $data['phone'])->first();
        if ($user && $user->role->isField()) {
            $this->otp->send($data['phone']);
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
        $user = User::where('phone', $data['phone'])->first();
        if (! $user || ! $user->role->isField() || ! $this->otp->verify($data['phone'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }
        $this->audit->log($user, 'auth.otp_login', null, [], $request->ip());

        return $this->tokenResponse($user, $data['device_name'] ?? 'agent-app');
    }

    /** Command-room staff sign in with email and password. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password) || $user->role->isField()) {
            $this->audit->log($user, 'auth.failed_login', null, ['email' => $data['email']], $request->ip(), $user?->campaign_id);
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
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
        $user = $request->user()->load(['campaign:id,name,state_name,election_date', 'pollingUnit.ward.lga', 'ward.lga', 'lga']);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'code' => $user->code,
            'role' => $user->role,
            'role_label' => $user->role->label(),
            'is_reserve' => $user->is_reserve,
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
