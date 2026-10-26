<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ResultRecorder;
use App\Services\SmsResultParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inbound SMS from the aggregator. Agents text "R 004 AC448 A214 B187 C22 D9 X6" when the app has no data.
 * The reply text is returned for the aggregator to send back to the agent.
 */
class SmsWebhookController extends Controller
{
    public function __invoke(Request $request, ResultRecorder $recorder, AuditLogger $audit): JsonResponse
    {
        $token = (string) config('pollproof.sms_webhook_token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->header('X-Webhook-Token')), 401);

        $data = $request->validate(['from' => ['required', 'string'], 'text' => ['required', 'string', 'max:480']]);
        $agent = User::where('phone', $data['from'])->where('role', Role::Agent)->first();
        if (! $agent) {
            return response()->json(['reply' => 'This number is not registered with PollProof.']);
        }

        $parsed = SmsResultParser::parse($data['text']);
        if (! $parsed) {
            return response()->json(['reply' => 'Format not recognised. Send: R <unit> AC<accredited> A<votes> B<votes> ... X<rejected>']);
        }

        $unit = PollingUnit::where('campaign_id', $agent->campaign_id)->where('code', $parsed['unit'])->first();
        if (! $unit || $unit->id !== $agent->polling_unit_id) {
            return response()->json(['reply' => "Unit {$parsed['unit']} is not your assigned polling unit."]);
        }

        try {
            $result = $recorder->record(
                agent: $agent,
                unit: $unit,
                votes: $parsed['votes'],
                accredited: $parsed['accredited'],
                rejected: $parsed['rejected'],
                capturedAt: now()->utc()->toIso8601ZuluString(),
                channel: 'sms',
            );
        } catch (ValidationException $e) {
            return response()->json(['reply' => 'Not saved: '.collect($e->errors())->flatten()->first()]);
        }
        $audit->log($agent, 'result.submitted_sms', $result, ['payload_hash' => $result->payload_hash], $request->ip());

        return response()->json(['reply' => "OK {$unit->code} total {$result->total_cast} ref {$result->fingerprint()}"]);
    }
}
