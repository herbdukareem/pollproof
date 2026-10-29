<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Incident;
use App\Models\ResultSubmission;
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
        $user = $request->user()->load(['campaign:id,name,state_name,election_date,freeze_at,declared_at,require_two_factor', 'pollingUnit.ward.lga', 'ward.lga', 'lga']);

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
            'rules' => ($c = Campaign::find($user->campaign_id)) ? [
                'geofence_radius_m' => $c->geofence_radius_m,
                'max_gps_accuracy_m' => $c->max_gps_accuracy_m,
                'require_selfie' => $c->require_selfie,
                'sms_number' => config('pollproof.sms_number'),
                'declared' => $c->isDeclared(),
            ] : null,
            'supervisor' => $this->supervisorFor($user),
            'today' => $user->role === Role::Agent ? $this->agentToday($user) : null,
        ]);
    }

    /** Who this field user calls first: the ward supervisor, or for a ward supervisor their LGA supervisor. */
    private function supervisorFor(User $user): ?array
    {
        if (! $user->role->isField() || $user->role === Role::LgaSupervisor) {
            return null;
        }
        $wardId = $user->pollingUnit?->ward_id ?? $user->ward_id;
        $lgaId = $user->pollingUnit?->ward?->lga_id ?? $user->lga_id ?? $user->ward?->lga_id;
        $sup = $user->role !== Role::WardSupervisor && $wardId
            ? User::where('campaign_id', $user->campaign_id)->where('role', Role::WardSupervisor)->where('ward_id', $wardId)->whereNull('deactivated_at')->first()
            : null;
        $sup ??= $lgaId ? User::where('campaign_id', $user->campaign_id)->where('role', Role::LgaSupervisor)->where('lga_id', $lgaId)->where('is_backup', false)->whereNull('deactivated_at')->first() : null;

        return $sup ? ['name' => $sup->name, 'code' => $sup->code, 'phone' => $sup->phone, 'role' => $sup->role->label()] : null;
    }

    /** What the agent has done today, so a reinstalled app picks up where it left off. */
    private function agentToday(User $user): array
    {
        $checkIn = $user->checkIns()->latest('id')->first();
        $result = ResultSubmission::where('user_id', $user->id)->where('polling_unit_id', $user->polling_unit_id)->where('is_current', true)->first();

        return [
            'check_in' => $checkIn ? ['id' => $checkIn->id, 'status' => $checkIn->status, 'counts' => $checkIn->counts(), 'distance_m' => $checkIn->distance_m, 'at' => $checkIn->captured_at] : null,
            'result' => $result ? [
                'id' => $result->id, 'status' => $result->status, 'fingerprint' => $result->fingerprint(), 'total_cast' => $result->total_cast,
                'has_photo' => $result->photo_path !== null, 'review_note' => $result->review_note, 'captured_at' => $result->captured_at, 'channel' => $result->channel,
            ] : null,
            'incidents' => $user->hasMany(Incident::class)->count(),
        ];
    }

    private function tokenResponse(User $user, string $name): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($name, [$user->role->value])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role, 'campaign_id' => $user->campaign_id],
        ]);
    }
}
