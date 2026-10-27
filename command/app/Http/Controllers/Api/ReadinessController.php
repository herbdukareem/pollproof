<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendSms;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadinessController extends Controller
{
    public function index(Request $request, ReadinessService $readiness): JsonResponse
    {
        return response()->json($readiness->report($request->user()->campaign));
    }

    /** Text a group of agents to close a readiness gap. Agents reply YES or NO to the confirmation message. */
    public function remind(Request $request, ReadinessService $readiness, AuditLogger $audit): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $data = $request->validate(['group' => ['required', 'in:not_installed,not_trained,not_confirmed']]);
        $agents = $readiness->group($campaign, $data['group']);
        $date = $campaign->election_date->format('l j F');
        $url = config('pollproof.app_download_url');

        foreach ($agents as $agent) {
            $unit = $agent->pollingUnit->code;
            SendSms::dispatch($agent->phone, match ($data['group']) {
                'not_installed' => "PollProof: you are the agent for {$unit}. Install the app from {$url} and sign in with this number. Agent ID {$agent->code}.",
                'not_trained' => "PollProof: please finish the practice run and the quiz in the app before {$date}.",
                'not_confirmed' => "PollProof: election day is {$date}. Reply YES if you will be at {$unit} by 7:30 am with your phone charged. Reply NO if you can't make it.",
            });
        }
        if ($data['group'] === 'not_installed') {
            User::whereIn('id', $agents->pluck('id'))->update(['invited_at' => now()]);
        }
        $audit->log($request->user(), 'readiness.reminded', null, ['group' => $data['group'], 'agents' => $agents->count()], $request->ip());

        return response()->json(['sent' => $agents->count()]);
    }
}
