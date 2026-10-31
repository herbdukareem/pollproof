<?php

namespace App\Services;

use App\Enums\Role;
use App\Jobs\NotifyAlert;
use App\Models\Alert;
use App\Models\Campaign;
use App\Models\CheckIn;
use App\Models\NotificationRule;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Checks that run every minute: escalating alerts nobody has acted on, units whose agent
 * hasn't checked in by the deadline on election day, and a growing verification queue.
 */
final class ElectionWatch
{
    public const VERIFY_BACKLOG = 20;

    public function __construct(private readonly AlertService $alerts) {}

    /** @return array{escalated: int, absent: int, backlog: int} */
    public function run(Campaign $campaign, ?Carbon $now = null): array
    {
        $now ??= now();

        return [
            'escalated' => $this->escalate($campaign, $now),
            'absent' => $this->absentAgents($campaign, $now),
            'backlog' => $this->verifyBacklog($campaign),
        ];
    }

    public function escalate(Campaign $campaign, Carbon $now): int
    {
        $rules = NotificationRule::where('campaign_id', $campaign->id)->where('enabled', true)
            ->whereNotNull('escalate_after_minutes')->get()->filter(fn ($r) => ! empty($r->escalate_to))->keyBy('event');
        $due = Alert::where('campaign_id', $campaign->id)->where('status', 'open')->whereNull('escalated_at')
            ->whereIn('type', $rules->keys())->get()
            ->filter(fn (Alert $a) => ($a->opened_at ?? $a->created_at)->lte($now->copy()->subMinutes($rules[$a->type]->escalate_after_minutes)));

        foreach ($due as $alert) {
            $alert->update(['escalated_at' => $now]);
            NotifyAlert::dispatch($alert->id, true);
        }

        return $due->count();
    }

    /** On election day after the check-in deadline, every unit without a counted check-in raises an alert. */
    public function absentAgents(Campaign $campaign, Carbon $now): int
    {
        $local = $now->copy()->setTimezone(config('pollproof.timezone'));
        $deadline = Carbon::parse($campaign->election_date->toDateString().' '.$campaign->checkin_deadline, config('pollproof.timezone'));
        if (! $local->isSameDay($deadline) || $local->lt($deadline)) {
            return 0;
        }

        $present = CheckIn::where('campaign_id', $campaign->id)->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED])
            ->onDay($deadline)->pluck('polling_unit_id')->unique();
        $agents = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNotNull('polling_unit_id')->pluck('code', 'polling_unit_id');
        $missing = PollingUnit::where('campaign_id', $campaign->id)->whereNotIn('id', $present)->get();
        $time = $deadline->format('g:i a');
        foreach ($missing as $unit) {
            $who = isset($agents[$unit->id]) ? "Agent {$agents[$unit->id]} has not checked in" : 'No agent is assigned';
            $this->alerts->raise($campaign->id, 'agent_absent', 'medium', "agent_absent:{$unit->id}",
                "{$unit->code}: no agent checked in by {$time}", "{$who}. Call them or send a reserve.", $unit);
        }

        return $missing->count();
    }

    public function verifyBacklog(Campaign $campaign): int
    {
        $waiting = ResultSubmission::where('campaign_id', $campaign->id)->where('is_current', true)->where('status', ResultSubmission::PENDING_REVIEW)->count();
        if ($waiting > self::VERIFY_BACKLOG) {
            // Re-alerts each time the queue grows by another 20, even if the last alert was resolved.
            $this->alerts->raise($campaign->id, 'verify_backlog', 'medium', 'verify_backlog', "{$waiting} results waiting to be verified",
                'Results only count in the verified tally after a second check. Add verifiers or ask the situation room to help.', null, ['band' => intdiv($waiting, self::VERIFY_BACKLOG)]);
        } else {
            $this->alerts->clear($campaign->id, 'verify_backlog');
        }

        return $waiting;
    }
}
