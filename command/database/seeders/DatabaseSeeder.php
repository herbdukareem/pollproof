<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Lga;
use App\Models\Party;
use App\Models\PollingUnit;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Seeder;

/**
 * A small demo state for local development: 2 LGAs, 4 wards, 12 polling units, 4 parties and one user per role.
 * Command-room users sign in with password "password"; field users with the OTP printed to the log.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $campaign = Campaign::create([
            'name' => 'Demo Governorship Campaign',
            'state_name' => 'Demo State',
            'election_date' => '2027-02-06',
        ]);

        foreach ([['A', 'Party A', true], ['B', 'Party B', false], ['C', 'Party C', false], ['D', 'Party D', false]] as $i => [$code, $name, $ours]) {
            Party::create(['campaign_id' => $campaign->id, 'code' => $code, 'name' => $name, 'is_ours' => $ours, 'sort_order' => $i]);
        }

        $n = 0;
        foreach ([['LGA-A', 'LGA A', 9.6139, 6.5569], ['LGA-B', 'LGA B', 9.0765, 7.3986]] as [$lgaCode, $lgaName, $lat, $lng]) {
            $lga = Lga::create(['campaign_id' => $campaign->id, 'code' => $lgaCode, 'name' => $lgaName]);
            foreach ([1, 2] as $w) {
                $ward = Ward::create(['campaign_id' => $campaign->id, 'lga_id' => $lga->id, 'code' => sprintf('W%02d', $w), 'name' => "Ward {$w}"]);
                foreach ([1, 2, 3] as $p) {
                    $n++;
                    PollingUnit::create([
                        'campaign_id' => $campaign->id,
                        'ward_id' => $ward->id,
                        'code' => sprintf('PU%03d', $n),
                        'name' => "Polling unit {$n}",
                        'latitude' => $lat + $w * 0.01 + $p * 0.002,
                        'longitude' => $lng + $w * 0.01,
                        'registered_voters' => 750,
                    ]);
                }
            }
        }

        $staff = [Role::Dg, Role::SituationRoom, Role::Verifier, Role::DataOfficer, Role::Legal, Role::Finance];
        foreach ($staff as $role) {
            User::factory()->role($role)->create([
                'campaign_id' => $campaign->id,
                'name' => $role->label(),
                'email' => $role->value.'@demo.test',
            ]);
        }

        $firstWard = Ward::where('campaign_id', $campaign->id)->orderBy('id')->first();
        User::factory()->role(Role::WardSupervisor)->create([
            'campaign_id' => $campaign->id, 'name' => 'Ward Supervisor', 'code' => 'WS-01', 'phone' => '08000000100',
            'ward_id' => $firstWard->id, 'lga_id' => $firstWard->lga_id,
        ]);
        User::factory()->role(Role::CollationAgent)->create([
            'campaign_id' => $campaign->id, 'name' => 'Collation Agent', 'code' => 'AG-C01', 'phone' => '08000000200',
            'ward_id' => $firstWard->id, 'lga_id' => $firstWard->lga_id,
        ]);

        foreach (PollingUnit::where('campaign_id', $campaign->id)->orderBy('id')->get() as $i => $unit) {
            User::factory()->role(Role::Agent)->create([
                'campaign_id' => $campaign->id,
                'name' => 'Agent '.($i + 1),
                'code' => sprintf('AG-%04d', $i + 1),
                'phone' => sprintf('080000010%02d', $i + 1),
                'polling_unit_id' => $unit->id,
                'ward_id' => $unit->ward_id,
            ]);
        }
        User::factory()->role(Role::Agent)->create([
            'campaign_id' => $campaign->id, 'name' => 'Reserve Agent', 'code' => 'AG-R001', 'phone' => '08000009001', 'is_reserve' => true,
        ]);
    }
}
