<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PollingUnitController extends Controller
{
    /**
     * Fix a unit's details. Supervisors use this to drop a GPS pin while standing at the unit,
     * so check-ins there can be verified.
     */
    public function update(Request $request, PollingUnit $unit, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($unit->campaign_id === $user->campaign_id, 404);
        abort_if($user->hasRole(Role::WardSupervisor) && $unit->ward_id !== $user->ward_id, 403, 'This unit is not in your ward.');
        abort_if($user->hasRole(Role::LgaSupervisor) && $unit->ward->lga_id !== $user->lga_id, 403, 'This unit is not in your LGA.');
        $user->campaign->abortIfFrozen();

        $data = $request->validate([
            'latitude' => ['required_with:longitude', 'nullable', 'numeric', 'between:4,14'],
            'longitude' => ['required_with:latitude', 'nullable', 'numeric', 'between:2,15'],
            'accuracy_m' => ['nullable', 'integer', 'min:0'],
            'name' => ['sometimes', 'string', 'max:160'],
            'registered_voters' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ], ['latitude.between' => 'That location is outside Nigeria.', 'longitude.between' => 'That location is outside Nigeria.']);
        $max = $user->campaign->max_gps_accuracy_m;
        if (($data['accuracy_m'] ?? 0) > $max) {
            abort(422, "GPS accuracy is {$data['accuracy_m']} m; wait for {$max} m or better before saving the pin.");
        }

        $before = $unit->only(['latitude', 'longitude', 'name', 'registered_voters']);
        $unit->update(collect($data)->except('accuracy_m')->all());
        $audit->log($user, 'unit.updated', $unit, ['before' => $before, 'after' => $unit->only(array_keys($before))], $request->ip());

        return response()->json($unit->only(['id', 'code', 'name', 'latitude', 'longitude', 'registered_voters']));
    }
}
