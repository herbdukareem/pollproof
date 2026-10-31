<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The order in which things last happened to each agent, for "latest first" lists.
 *
 * Read from the audit log, whose entries are numbered in the order the server recorded them:
 * the agent's own actions (check-in, result, incident) and actions on their records by others
 * (a supervisor approving their check-in, a verifier checking their result, an edit to the agent).
 * Device clocks and election-day timestamps can't push an older action above a newer one.
 */
final class AgentActivity
{
    /** @return Collection<int, int> agent id => sequence of their latest action (higher is newer) */
    public function sequence(int $campaignId): Collection
    {
        return DB::table('audit_logs as a')
            ->leftJoin('check_ins as c', fn ($j) => $j->on('c.id', '=', 'a.subject_id')->where('a.subject_type', CheckIn::class))
            ->leftJoin('result_submissions as r', fn ($j) => $j->on('r.id', '=', 'a.subject_id')->where('a.subject_type', ResultSubmission::class))
            ->where('a.campaign_id', $campaignId)
            ->selectRaw('COALESCE(c.user_id, r.user_id, CASE WHEN a.subject_type = ? THEN a.subject_id END, a.user_id) AS agent_id, MAX(a.id) AS seq', [User::class])
            ->groupBy('agent_id')
            ->pluck('seq', 'agent_id')
            ->map(fn ($s) => (int) $s);
    }
}
