<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
