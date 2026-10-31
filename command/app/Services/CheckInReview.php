<?php

namespace App\Services;

use App\Models\CheckIn;

/**
 * A check-in a supervisor has to confirm (made outside the unit area, or by USSD with no GPS)
 * opens a low alert, which tells the ward supervisor and the situation room. Approving, rejecting
 * or a later in-area check-in closes it. One alert per unit, so a repeat check-in doesn't stack up.
 */
final class CheckInReview
{
    public function __construct(private readonly AlertService $alerts) {}

    public function waiting(CheckIn $checkIn): void
    {
        $checkIn->loadMissing(['user', 'pollingUnit']);
        $how = match (true) {
            $checkIn->channel === 'ussd' => 'by USSD, with no GPS',
            $checkIn->distance_m !== null => "{$checkIn->distance_m} m from the unit",
            default => 'but the unit has no GPS point',
        };
        $agent = trim($checkIn->user->code.' '.$checkIn->user->name);
        $this->alerts->raise(
            $checkIn->campaign_id, 'checkin_review', 'low', "checkin_review:{$checkIn->polling_unit_id}",
            "{$checkIn->pollingUnit->code}: check-in to confirm",
            "{$agent} checked in {$how}. Approve or reject it on the Agents page.",
            $checkIn->pollingUnit, ['check_in_id' => $checkIn->id],
        );
    }

    public function settled(CheckIn $checkIn): void
    {
        $this->alerts->clear($checkIn->campaign_id, "checkin_review:{$checkIn->polling_unit_id}");
    }
}
