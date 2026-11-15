<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Election;
use App\Models\Lga;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Services\ResultRecorder;
use Illuminate\Database\Seeder;

/**
 * State Assembly races alongside the demo governorship, on the same day, with results from most
 * of their units: what a general election day looks like in the command room.
 *
 *   php artisan db:seed --class=RacesDemoSeeder   (after PitchDemoSeeder)
 */
class RacesDemoSeeder extends Seeder
{
    /** [name, code, LGAs, how our candidate does against the governorship figures] */
    private const RACES = [
        ['Assembly (Bida I)', 'HOA-BIDA1', ['Bida'], 1.12],
        ['Assembly (Chanchaga)', 'HOA-CHAN', ['Chanchaga'], 0.6],
        ['Assembly (Agaie)', 'HOA-AGAI', ['Agaie'], 0.97],
        ['Senate (Niger South)', 'SEN-S', ['Agaie', 'Bida', 'Gbako', 'Katcha', 'Lapai', 'Lavun', 'Mokwa', 'Edati'], 1.03],
    ];

    public function run(ResultRecorder $recorder): void
    {
        mt_srand(2028);
        $campaign = Campaign::firstOrFail();
        $parties = $campaign->parties()->get();
        $ours = $parties->firstWhere('is_ours', true);
        $governorship = $campaign->primaryElection();

        foreach (self::RACES as $i => [$name, $code, $lgaNames, $swing]) {
            $type = str_starts_with($code, 'SEN') ? Election::SENATE : Election::ASSEMBLY;
            $race = Election::updateOrCreate(['campaign_id' => $campaign->id, 'code' => $code], [
                'type' => $type,
                'name' => $name,
                'election_date' => $governorship->election_date,
                'sort_order' => $i + 1,
                'announce' => false,
            ]);
            $race->areas()->delete();
            foreach (Lga::where('campaign_id', $campaign->id)->whereIn('name', $lgaNames)->pluck('id') as $lga) {
                $race->areas()->create(['lga_id' => $lga]);
            }
            $race->parties()->sync($parties->values()->mapWithKeys(fn ($p, $k) => [$p->id => ['sort_order' => $k]])->all());

            // Most units report; each result follows the governorship sheet with our candidate
            // running ahead of or behind the party's governorship figures.
            $units = $race->units()->with('ward.lga')->get();
            foreach ($units as $unit) {
                $gov = $unit->currentResultFor($governorship->id);
                if (! $gov || mt_rand(1, 100) > 88 || $unit->currentResultFor($race->id)) {
                    continue;
                }
                $agent = User::where('polling_unit_id', $unit->id)->where('role', Role::Agent)->first();
                if (! $agent) {
                    continue;
                }
                $votes = $gov->votesByParty();
                foreach ($votes as $party => $n) {
                    $votes[$party] = max(0, (int) round($n * ($party === $ours->code ? $swing : 1 + (1 - $swing) * 0.6) * mt_rand(92, 104) / 100));
                }
                $rejected = max(0, $gov->rejected + mt_rand(-2, 3));
                $accredited = max($gov->accredited, array_sum($votes) + $rejected);
                $result = $recorder->record(
                    agent: $agent, unit: $unit, election: $race, votes: $votes, accredited: $accredited, rejected: $rejected,
                    capturedAt: $gov->captured_at->copy()->addMinutes(mt_rand(4, 25))->utc()->toIso8601ZuluString(), channel: 'sms',
                );
                if (mt_rand(1, 100) <= 70) {
                    $result->update(['status' => ResultSubmission::APPROVED, 'reviewed_at' => $result->created_at]);
                }
            }
            $this->command?->info("{$name}: {$race->units()->count()} units, ".$race->results()->where('is_current', true)->count().' results');
        }
    }
}
