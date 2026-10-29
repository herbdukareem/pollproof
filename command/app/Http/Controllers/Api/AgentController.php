<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    /** Send a reserve (or any agent) to a polling unit; whoever held it loses access to it. */
    public function assign(Request $request, User $agent, AuditLogger $audit): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        abort_unless($agent->campaign_id === $user->campaign_id && $agent->hasRole(Role::Agent), 404);
        $data = $request->validate(['polling_unit_id' => ['required', 'integer']]);
        $unit = PollingUnit::where('campaign_id', $user->campaign_id)->with('ward')->findOrFail($data['polling_unit_id']);
        abort_if($user->hasRole(Role::WardSupervisor) && $unit->ward_id !== $user->ward_id, 403, 'This unit is not in your ward.');
        abort_if($user->hasRole(Role::LgaSupervisor) && $unit->ward->lga_id !== $user->lga_id, 403, 'This unit is not in your LGA.');

        $replaced = DB::transaction(function () use ($agent, $unit) {
            $previous = User::where('polling_unit_id', $unit->id)->where('role', Role::Agent)->where('id', '!=', $agent->id)->first();
            $previous?->update(['polling_unit_id' => null]);
            $agent->update(['polling_unit_id' => $unit->id, 'ward_id' => $unit->ward_id, 'lga_id' => $unit->ward->lga_id, 'is_reserve' => false]);

            return $previous;
        });
        $audit->log($user, 'agent.assigned', $agent, ['polling_unit' => $unit->code, 'replaced_agent_id' => $replaced?->id], $request->ip());

        return response()->json(['agent_id' => $agent->id, 'polling_unit' => $unit->code, 'replaced_agent_id' => $replaced?->id]);
    }

    /**
     * Edit an agent: correct their name or phone, release them from their unit (they become a reserve),
     * or deactivate them. Deactivating frees their unit and signs them out of the app at once;
     * reactivating brings them back as a reserve.
     */
    public function update(Request $request, User $agent, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $user->campaign->abortIfDeclared();
        abort_unless($agent->campaign_id === $user->campaign_id && $agent->hasRole(Role::Agent), 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
            'release' => ['sometimes', 'accepted'],
        ]);

        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = trim($data['name']);
        }
        if (isset($data['phone'])) {
            $phone = Phone::normalize($data['phone']);
            abort_unless($phone, 422, 'That is not a valid Nigerian phone number.');
            $request->merge(['normalized_phone' => $phone])->validate(['normalized_phone' => [Rule::unique('users', 'phone')->ignore($agent->id)]], ['normalized_phone.unique' => 'Another person already uses this phone number.']);
            $changes['phone'] = $phone;
        }
        if (! empty($data['release'])) {
            $changes += ['polling_unit_id' => null, 'is_reserve' => true];
        }
        if (array_key_exists('active', $data)) {
            $changes['deactivated_at'] = $data['active'] ? null : now();
            if (! $data['active']) {
                $changes += ['polling_unit_id' => null];
            } else {
                $changes += ['is_reserve' => true];
            }
        }

        $before = $agent->only(array_keys($changes));
        DB::transaction(function () use ($agent, $changes, $data) {
            $agent->update($changes);
            if (($data['active'] ?? true) === false || isset($changes['phone'])) {
                $agent->tokens()->delete();
            }
        });
        $action = match (true) {
            array_key_exists('active', $data) => $data['active'] ? 'agent.reactivated' : 'agent.deactivated',
            ! empty($data['release']) => 'agent.released',
            default => 'agent.updated',
        };
        $audit->log($user, $action, $agent, ['before' => $before, 'after' => collect($changes)->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v)->all()], $request->ip());

        return response()->json(['id' => $agent->id, 'name' => $agent->name, 'phone' => $agent->phone, 'active' => $agent->isActive(), 'polling_unit_id' => $agent->polling_unit_id]);
    }
}
