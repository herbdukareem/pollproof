<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TallyService;
use Illuminate\Database\Seeder;

/**
 * Declares the demo result (Party B inflated by 40, as at the Ward 1 collation) and opens a petition,
 * so the close-out and tribunal screens have something to show. Run after ElectionDaySeeder.
 */
class AfterElectionSeeder extends Seeder
{
    public function run(AuditLogger $audit, TallyService $tally): void
    {
        $campaign = Campaign::firstOrFail();
        $dg = User::where('campaign_id', $campaign->id)->where('role', Role::Dg)->firstOrFail();
        $legal = User::where('campaign_id', $campaign->id)->where('role', Role::Legal)->firstOrFail();
        $data = User::where('campaign_id', $campaign->id)->where('role', Role::DataOfficer)->firstOrFail();

        $votes = collect($tally->summary($campaign)['candidates'])->pluck('votes', 'party')->all();
        $votes['B'] += 40;
        $campaign->update(['declared_at' => $campaign->election_date->copy()->addDay()->setTime(15, 10), 'declared_votes' => $votes]);
        $audit->log($dg, 'closeout.declared', $campaign, ['votes' => $votes]);

        $unit = fn ($code) => PollingUnit::where('campaign_id', $campaign->id)->where('code', $code)->value('id');
        $case = $campaign->tribunalCases()->create([
            'title' => 'Party B v INEC and others',
            'petitioner' => 'Party B',
            'milestones' => [
                ['date' => 'Sun 7 Feb 2027, 4:10 pm', 'label' => 'Result declared'],
                ['date' => 'Sat 27 Feb 2027', 'label' => 'Petition filed by Party B'],
                ['date' => 'Wed 3 Mar 2027', 'label' => 'Petition served on us'],
                ['date' => 'Set by counsel', 'label' => 'Our reply due'],
            ],
        ]);
        $case->grounds()->createMany([
            ['sort_order' => 1, 'label' => 'Over-voting', 'claim' => 'Votes exceeded accreditation at polling units in Ward 1, LGA A',
                'answer' => 'All three units have a signed EC8A record. Votes cast are below accredited voters at each unit. The over-vote appears only on the Ward 1 collation sheet, where Party B was given 40 more votes than the units add up to.',
                'evidence_note' => '3 signed unit results · Ward 1 EC8B comparison', 'unit_ids' => [$unit('PU001'), $unit('PU002'), $unit('PU003')], 'owner_id' => $legal->id, 'status' => 'ready'],
            ['sort_order' => 2, 'label' => 'Non-upload', 'claim' => 'Results from PU009 were never on IReV',
                'answer' => 'Our data officer recorded at 6:10 pm that PU009 was not on IReV. We have no agent result for that unit either.',
                'evidence_note' => 'IReV check record', 'unit_ids' => [$unit('PU009')], 'owner_id' => $data->id, 'status' => 'in_progress'],
            ['sort_order' => 3, 'label' => 'Agents obstructed', 'claim' => 'Party B agents were kept out of units in LGA B',
                'answer' => null, 'evidence_note' => 'Statements being collected', 'unit_ids' => [$unit('PU011')], 'owner_id' => $legal->id, 'status' => 'in_progress'],
        ]);
        $case->tasks()->createMany([
            ['title' => 'Pull all Ward 1 unit records', 'owner_id' => $data->id, 'due_on' => '2027-03-04', 'done_at' => now(), 'done_by' => $data->id],
            ['title' => 'Collect agent statement for PU011', 'owner_id' => $legal->id, 'due_on' => '2027-03-08'],
            ['title' => 'Review reply bundle with counsel', 'owner_id' => $legal->id, 'due_on' => '2027-03-10'],
        ]);
    }
}
