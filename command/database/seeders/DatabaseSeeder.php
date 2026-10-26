<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Device;
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
            'freeze_at' => '2027-01-30',
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
            'ward_id' => $firstWard->id, 'lga_id' => $firstWard->lga_id, 'invited_at' => now(), 'trained_at' => now(), 'quiz_score' => 10,
        ]);

        $this->supervisors($campaign);
        $this->onboarding($campaign);

        // One unit still without a location, as on most real lists.
        PollingUnit::where('campaign_id', $campaign->id)->where('code', 'PU012')->update(['latitude' => null, 'longitude' => null]);
    }

    /** LGA A fully covered; LGA B has its lead but Ward 2 is still open. */
    private function supervisors(Campaign $campaign): void
    {
        $lgas = Lga::where('campaign_id', $campaign->id)->orderBy('code')->get();
        $ward = fn ($lga, $code) => Ward::where('lga_id', $lga->id)->where('code', $code)->first();
        $people = [
            ['SV-001', 'Lead, LGA A', '08000000301', Role::LgaSupervisor, $lgas[0], null, false],
            ['SV-002', 'Backup, LGA A', '08000000302', Role::LgaSupervisor, $lgas[0], null, true],
            ['SV-003', 'Lead, LGA B', '08000000303', Role::LgaSupervisor, $lgas[1], null, false],
            ['WS-02', 'Ward 2 Supervisor, LGA A', '08000000102', Role::WardSupervisor, $lgas[0], $ward($lgas[0], 'W02'), false],
            ['WS-03', 'Ward 1 Supervisor, LGA B', '08000000103', Role::WardSupervisor, $lgas[1], $ward($lgas[1], 'W01'), false],
        ];
        foreach ($people as [$code, $name, $phone, $role, $lga, $w, $backup]) {
            User::factory()->role($role)->create([
                'campaign_id' => $campaign->id, 'code' => $code, 'name' => $name, 'phone' => $phone,
                'lga_id' => $lga->id, 'ward_id' => $w?->id, 'is_backup' => $backup,
            ]);
        }
    }

    /** Agents at different onboarding steps, so the readiness and roster screens have something to show. */
    private function onboarding(Campaign $campaign): void
    {
        $agents = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNotNull('polling_unit_id')->orderBy('code')->get();
        foreach ($agents as $i => $agent) {
            $n = $i + 1;
            if ($n <= 11) {
                Device::create(['user_id' => $agent->id, 'public_key' => base64_encode(random_bytes(32))]);
            }
            $agent->update([
                'invited_at' => now()->subDays(10),
                'practice_passed_at' => $n <= 10 ? now()->subDays(6) : null,
                'quiz_score' => $n <= 10 ? ($n <= 9 ? 9 : 6) : null,
                'trained_at' => $n <= 9 ? now()->subDays(5) : null,
                'confirmed_at' => $n <= 7 ? now()->subDay() : null,
            ]);
        }
    }
}
