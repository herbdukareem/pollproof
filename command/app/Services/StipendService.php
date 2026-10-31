<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CheckIn;
use App\Models\ResultSubmission;
use App\Models\User;

/**
 * An agent earns the election-day stipend only after a verified (or supervisor-approved)
 * check-in at their unit and a result for that unit (signed in the app, or sent by SMS).
 */
final class StipendService
{
    /** @return list<array<string,mixed>> */
    public function eligibility(Campaign $campaign): array
    {
        $agents = User::where('campaign_id', $campaign->id)
            ->where('role', Role::Agent)
            ->whereNotNull('polling_unit_id')
            ->with('pollingUnit')
            ->orderBy('code')
            ->get();

        $checkedIn = CheckIn::where('campaign_id', $campaign->id)
            ->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED])
            ->orderBy('captured_at')
            ->get(['user_id', 'polling_unit_id', 'captured_at'])
            ->mapWithKeys(fn ($c) => [$c->user_id.':'.$c->polling_unit_id => $c->captured_at]); // the latest wins
        $outside = CheckIn::where('campaign_id', $campaign->id)
            ->whereIn('status', [CheckIn::PENDING_REVIEW, CheckIn::REJECTED])
            ->pluck('user_id')->flip();
        $signed = ResultSubmission::where('campaign_id', $campaign->id)
            ->where('status', '!=', ResultSubmission::SENT_BACK)
            ->orderBy('id')
            ->get(['user_id', 'polling_unit_id', 'captured_at'])
            ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->polling_unit_id => $r->captured_at]);

        return $agents->map(function (User $a) use ($checkedIn, $outside, $signed) {
            $key = $a->id.':'.$a->polling_unit_id;
            $hasCheckIn = $checkedIn->has($key);
            $hasResult = $signed->has($key);
            $reason = match (true) {
                $hasCheckIn && $hasResult => null,
                ! $hasCheckIn && $outside->has($a->id) => 'Checked in outside unit area',
                ! $hasCheckIn => 'Absent or never checked in',
                default => 'No signed result yet',
            };

            return [
                'agent_id' => $a->id,
                'agent_code' => $a->code,
                'name' => $a->name,
                'polling_unit' => $a->pollingUnit?->code,
                'checked_in' => $hasCheckIn,
                'signed_result' => $hasResult,
                'checked_in_at' => $checkedIn->get($key),
                'result_at' => $signed->get($key),
                'eligible' => $reason === null,
                'reason' => $reason,
            ];
        })->all();
    }
}
