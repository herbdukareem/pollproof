<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Election;
use App\Models\PollingUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Which race a request is about. */
final class Races
{
    /**
     * The race named by ?election= (an id), or the campaign's primary race when none is named.
     * With $allowAll, no race named means "all races" (null).
     */
    public static function fromRequest(Request $request, bool $allowAll = false): ?Election
    {
        $id = $request->input('election');
        $campaign = $request->user()->campaign;
        if ($id === null || $id === '' || $id === 'all') {
            return $allowAll ? null : ($campaign->primaryElection() ?? abort(422, 'Add a race first, under Setup, Races.'));
        }

        return Election::where('campaign_id', $campaign->id)->findOrFail((int) $id);
    }

    /**
     * The races an agent at this unit sends results for: those on the current election day
     * whose area includes the unit.
     *
     * @return Collection<int, Election>
     */
    public static function atUnit(PollingUnit $unit): Collection
    {
        $campaign = Campaign::findOrFail($unit->campaign_id);

        return $campaign->electionsOn($campaign->currentElectionDate())
            ->load('areas', 'parties')
            ->filter(fn (Election $e) => $e->coversUnit($unit))
            ->values();
    }

    /** The race an agent's submission is for: named by code, or their unit's first race. */
    public static function forAgent(User $agent, PollingUnit $unit, ?string $code): Election
    {
        if ($code !== null && $code !== '') {
            $race = Election::where('campaign_id', $unit->campaign_id)->where('code', strtoupper(trim($code)))->first();
            abort_unless($race, 422, "There is no race with the code {$code}.");
            abort_unless($race->coversUnit($unit), 422, "{$unit->code} is not in the {$race->name} race.");

            return $race;
        }

        return self::atUnit($unit)->first()
            ?? $agent->campaign->primaryElection()
            ?? abort(422, 'No race is set up for this campaign yet.');
    }
}
