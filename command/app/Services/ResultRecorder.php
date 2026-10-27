<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Party;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a polling-unit result. A record is never edited: a correction is a new
 * submission that supersedes the previous one, and only the newest is "current".
 */
final class ResultRecorder
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly Reconciler $reconciler,
    ) {}

    /**
     * @param  array<string,int>  $votes  votes keyed by party code
     */
    public function record(
        User $agent,
        PollingUnit $unit,
        array $votes,
        int $accredited,
        int $rejected,
        string $capturedAt,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $photoPath = null,
        ?string $photoSha256 = null,
        ?Device $device = null,
        ?string $signature = null,
        ?int $supersedesId = null,
        ?string $correctionReason = null,
        string $channel = 'app',
    ): ResultSubmission {
        $parties = Party::where('campaign_id', $unit->campaign_id)->get()->keyBy('code');
        $unknown = array_diff(array_keys($votes), $parties->keys()->all());
        if ($unknown) {
            throw ValidationException::withMessages(['votes' => 'Unknown party code: '.implode(', ', $unknown)]);
        }
        // The signature covers exactly what the phone sent; parties it left out are stored as zero afterwards.
        $signedVotes = array_map('intval', $votes);
        foreach ($parties->keys() as $code) {
            $votes[$code] = (int) ($votes[$code] ?? 0);
        }

        $previous = null;
        if ($supersedesId !== null) {
            $previous = ResultSubmission::where('id', $supersedesId)->where('polling_unit_id', $unit->id)->first();
            if (! $previous) {
                throw ValidationException::withMessages(['supersedes_id' => 'The record being corrected is not a result for this unit.']);
            }
        }

        // The phone retries when it can't tell whether a send went through. The same signed payload
        // from the same agent is the same record, so return it instead of refusing or duplicating it.
        $canonical = ResultSigner::canonical($unit->code, $accredited, $rejected, $signedVotes, $photoSha256, $latitude, $longitude, $capturedAt, $supersedesId);
        $duplicate = ResultSubmission::where('polling_unit_id', $unit->id)->where('user_id', $agent->id)
            ->where('payload_hash', ResultSigner::hash($canonical))->first();
        if ($duplicate) {
            return $duplicate;
        }

        // A unit has one current result. Anything after it must be an explicit correction, except the
        // signed app copy of figures the agent first sent by SMS, which is linked automatically.
        $existing = ResultSubmission::where('polling_unit_id', $unit->id)->where('is_current', true)->first();
        if ($existing && ! $previous) {
            if ($channel === 'app' && $existing->channel === 'sms' && $existing->user_id === $agent->id) {
                $previous = $existing;
                $correctionReason ??= 'Signed app copy of the SMS result';
            } else {
                throw ValidationException::withMessages(['supersedes_id' => "This unit already has a result (#{$existing->id}). Send a correction that references it."]);
            }
        }

        $signatureValid = $device && $signature && ! $device->revoked_at
            && ResultSigner::verify($canonical, $signature, $device->public_key);

        if ($channel === 'app' && ! $signatureValid) {
            throw ValidationException::withMessages(['signature' => 'The result signature does not match this device and payload.']);
        }

        $totalValid = array_sum($votes);
        $totalCast = $totalValid + $rejected;
        $flags = [];
        if ($totalCast > $accredited) {
            $flags[] = 'overvote';
        }

        $result = DB::transaction(function () use ($agent, $unit, $votes, $parties, $accredited, $rejected, $capturedAt, $latitude, $longitude, $photoPath, $photoSha256, $device, $signature, $signatureValid, $previous, $correctionReason, $channel, $canonical, $totalValid, $totalCast, $flags) {
            ResultSubmission::where('polling_unit_id', $unit->id)->where('is_current', true)->update(['is_current' => false]);

            $result = ResultSubmission::create([
                'campaign_id' => $unit->campaign_id,
                'polling_unit_id' => $unit->id,
                'user_id' => $agent->id,
                'device_id' => $device?->id,
                'supersedes_id' => $previous?->id,
                'correction_reason' => $correctionReason,
                'channel' => $channel,
                'accredited' => $accredited,
                'rejected' => $rejected,
                'total_valid' => $totalValid,
                'total_cast' => $totalCast,
                'photo_path' => $photoPath,
                'photo_sha256' => $photoSha256,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'captured_at' => $capturedAt,
                'payload_hash' => ResultSigner::hash($canonical),
                'signature' => $signature,
                'signature_valid' => $signatureValid,
                'is_current' => true,
                'status' => ResultSubmission::PENDING_REVIEW,
                'flags' => $flags,
            ]);

            foreach ($votes as $code => $count) {
                $result->votes()->create(['party_id' => $parties[$code]->id, 'votes' => $count]);
            }

            return $result;
        });

        $key = "overvote:unit:{$unit->id}";
        if (in_array('overvote', $flags, true)) {
            $this->alerts->raise($unit->campaign_id, 'overvote', 'high', $key,
                "{$unit->code}: votes cast exceed accreditation",
                "Our agent's sheet shows {$totalCast} votes cast against {$accredited} accredited.",
                $result, ['total_cast' => $totalCast, 'accredited' => $accredited, 'source' => 'agent_result']);
        } else {
            $this->alerts->clear($unit->campaign_id, $key);
        }

        $this->reconciler->afterUnitResult($result);

        return $result;
    }
}
