<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The small numbers beside the command-room menu: what is waiting for someone right now.
 * Only counts the signed-in role can act on are returned.
 */
class NavCountsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $campaign = $user->campaign_id;
        $counts = [
            'alerts' => Alert::where('campaign_id', $campaign)->where('status', '!=', 'resolved')->count(),
            'collation' => Alert::where('campaign_id', $campaign)->where('status', '!=', 'resolved')->where('type', 'collation_mismatch')->count(),
            'incidents' => Incident::where('campaign_id', $campaign)->where('status', 'open')->count(),
        ];

        if ($user->hasRole(Role::Verifier, Role::SituationRoom, Role::Dg)) {
            $counts['verify'] = ResultSubmission::where('campaign_id', $campaign)->where('is_current', true)->where('status', ResultSubmission::PENDING_REVIEW)->count();
        }

        if ($user->hasRole(Role::SituationRoom, Role::Dg)) {
            $counts['structure'] = PollingUnit::where('campaign_id', $campaign)->whereNull('latitude')->count();
            $covered = User::where('campaign_id', $campaign)->where('role', Role::WardSupervisor)->whereNull('deactivated_at')->whereNotNull('ward_id')->distinct()->pluck('ward_id');
            $counts['supervisors'] = Ward::where('campaign_id', $campaign)->whereNotIn('id', $covered)->count();
        }

        return response()->json($counts);
    }
}
