<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactor;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Command-room accounts. The DG adds people with a temporary password that is shown once and must
 * be changed at first sign-in. Field staff are managed through the roster and supervisor screens.
 */
class UserController extends Controller
{
    public const STAFF_ROLES = [Role::Dg, Role::SituationRoom, Role::Verifier, Role::DataOfficer, Role::Legal, Role::Finance];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $sorts = [
            'latest' => [fn ($u) => $u['id'], 'desc'],
            'name' => [fn ($u) => $u['name'], 'asc'],
            'role' => [fn ($u) => $u['role_label'], 'asc'],
            'status' => [fn ($u) => $u['active'] ? ($u['must_change_password'] ? 1 : 0) : 2, 'asc'],
            'twofa' => [fn ($u) => $u['two_factor'], 'asc'],
            'active' => [fn ($u) => $u['last_active_at'], 'desc'],
        ];
        $data = $request->validate(ListSort::rules($sorts));
        $campaignId = $request->user()->campaign_id;
        $users = User::where('campaign_id', $campaignId)->whereIn('role', self::STAFF_ROLES)->orderBy('role')->orderBy('name')->get();

        return response()->json([
            'roles' => collect(self::STAFF_ROLES)->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label(), 'count' => $users->where('role', $r)->whereNull('deactivated_at')->count()]),
            'data' => ListSort::apply($users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => $u->role->label(),
                'active' => $u->isActive(),
                'must_change_password' => $u->must_change_password,
                'two_factor' => $u->hasTwoFactor(),
                'last_active_at' => $u->last_active_at,
                'is_me' => $u->id === $request->user()->id,
            ]), $data, $sorts),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, self::STAFF_ROLES))],
        ]);
        $password = $this->temporaryPassword();
        $user = User::create($data + [
            'campaign_id' => $request->user()->campaign_id,
            'password' => $password,
            'must_change_password' => true,
        ]);
        $this->audit->log($request->user(), 'user.created', $user, ['role' => $data['role'], 'email' => $data['email']], $request->ip());

        return response()->json(['id' => $user->id, 'temporary_password' => $password], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $this->authorizeTarget($me, $user);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(array_map(fn ($r) => $r->value, self::STAFF_ROLES))],
            'active' => ['sometimes', 'boolean'],
        ]);
        abort_if($user->id === $me->id && (($data['active'] ?? true) === false || ($data['role'] ?? $me->role->value) !== $me->role->value),
            422, 'You cannot deactivate yourself or change your own role.');

        $changes = collect($data)->except('active')->all();
        if (array_key_exists('active', $data)) {
            $changes['deactivated_at'] = $data['active'] ? null : now();
        }
        DB::transaction(function () use ($user, $changes, $data) {
            $user->update($changes);
            if (($data['active'] ?? true) === false || isset($data['role'])) {
                $this->signOutEverywhere($user);
            }
        });
        $this->audit->log($me, 'user.updated', $user, $data, $request->ip());

        return response()->json(['id' => $user->id, 'active' => $user->isActive(), 'role' => $user->role]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request->user(), $user);
        $password = $this->temporaryPassword();
        $user->update(['password' => $password, 'must_change_password' => true]);
        $this->signOutEverywhere($user);
        $this->audit->log($request->user(), 'user.password_reset', $user, [], $request->ip());

        return response()->json(['temporary_password' => $password]);
    }

    /** For someone who lost their phone: they set two-step sign-in up again at their next sign-in. */
    public function resetTwoFactor(Request $request, User $user, TwoFactor $twoFactor): JsonResponse
    {
        $this->authorizeTarget($request->user(), $user);
        abort_if($user->id === $request->user()->id, 422, 'Turn off your own two-step sign-in from your account page.');
        $twoFactor->disable($user);
        $this->signOutEverywhere($user);
        $this->audit->log($request->user(), 'user.two_factor_reset', $user, [], $request->ip());

        return response()->json(['two_factor' => false]);
    }

    private function authorizeTarget(User $me, User $user): void
    {
        abort_unless($user->campaign_id === $me->campaign_id && in_array($user->role, self::STAFF_ROLES, true), 404);
    }

    private function signOutEverywhere(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /** Easy to read out over the phone: no 0/O or 1/l. */
    private function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';

        return collect(range(1, 3))->map(fn () => collect(range(1, 4))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode(''))->implode('-');
    }
}
