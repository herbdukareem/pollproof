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
use App\Services\ResultSigner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A demo election in Niger State at full size: the real 25 LGAs and 274 wards, with
 * PITCH_DEMO_UNITS polling units per ward (default 6: 1,644 units, about 550,000 votes), then
 * election day played through the real API: check-ins, signed results with sample sheet
 * photos, approvals, incidents, ward and LGA collation with mismatches, and IReV checks.
 * Polling unit names, people, parties ("Party A" to "Party D") and every figure are invented.
 *
 *   php artisan migrate:fresh --seed --seeder=PitchDemoSeeder --force
 *
 * The default takes about ten minutes; PITCH_DEMO_UNITS=3 is quicker. Command-room users
 * sign in as <role>@demo.test with password "password".
 */
class PitchDemoSeeder extends Seeder
{
    /**
     * Niger State's 25 LGAs and 274 wards (INEC registration areas), with approximate headquarters
     * coordinates. The vote shares are invented for the demo.
     * code, name, lat, lng, our share, main rival share, wards
     */
    private const LGAS = [
        ['01', 'Agaie', 9.01, 6.32, 0.51, 0.36, ['Baro', 'Boku', 'Ekobadeggi', 'Ekossa', 'Ekowugi', 'Ekowuna', 'Etsugaie', 'Dauaci', 'Kutiriko', 'Magaji', 'Tagagi']],
        ['02', 'Agwara', 10.71, 4.58, 0.43, 0.41, ['Adehe', 'Agwata', 'Busuru', 'Gallah', 'Kashini', 'Kokoli', 'Mago', 'Papiri', 'Rofia', 'Suteku']],
        ['03', 'Bida', 9.08, 6.01, 0.56, 0.30, ['Bariki', 'Ceniyan', 'Dokodza', 'Kyari', 'Landzun', 'Masaba I', 'Masaba II', 'Masaga I', 'Masaga II', 'Mayaki Ndajiya', 'Nassarafu', 'Umaru/Majigi I', 'Umaru/Majigi II', 'Wadata']],
        ['04', 'Borgu', 9.88, 4.52, 0.38, 0.47, ['Babanna', 'Dugga', 'Karabonde', 'Konkoso', 'Malale', 'New Bussa', 'Kabe/Pissa', 'Shagunu', 'Wawa', 'Riverine']],
        ['05', 'Bosso', 9.70, 6.48, 0.53, 0.33, ['Beji', 'Bosso Central I', 'Bosso Central II', 'Chanchaga', 'Garatu', 'Kampala', 'Kodo', 'Maikunkele', 'Maitumbi', 'Shata']],
        ['06', 'Chanchaga', 9.61, 6.55, 0.57, 0.29, ['Limawa \'A\'', 'Limawa \'B\'', 'Makera', 'Minna Central', 'Minna South', 'Nassarawa \'A\'', 'Nassarawa \'B\'', 'Nassarawa \'C\'', 'Sabon Gari', 'Tudun Wada North', 'Tudun Wada South']],
        ['07', 'Edati', 9.05, 5.80, 0.46, 0.38, ['Enagi', 'Etsu Tasha', 'Fazhi', 'Gazhe I', 'Gazhe II', 'Gbangban', 'Gonagi', 'Guzan', 'Rokota', 'Sakpe']],
        ['08', 'Gbako', 9.36, 6.03, 0.49, 0.36, ['Batagi', 'Batako', 'Edokota', 'Edozhigi', 'Etsu Audu', 'Gbadafu', 'Gogata', 'Lemu', 'Nuwankota', 'Sammajiko']],
        ['09', 'Gurara', 9.12, 7.05, 0.33, 0.50, ['Bonu', 'Diko', 'Gawu', 'Izom', 'Kabo', 'Kwaka', 'Lambata', 'Lefu', 'Shako', 'Tufa']],
        ['10', 'Katcha', 8.77, 6.31, 0.48, 0.37, ['Bakeko', 'Badeggi', 'Bisanti', 'Dzwafu', 'Edotsu', 'Essa', 'Gbakogi', 'Katcha', 'Kataregi', 'Sidi Saba']],
        ['11', 'Kontagora', 10.40, 5.47, 0.45, 0.40, ['Arewa', 'Central', 'Gabas', 'Kudu', 'Magajiya', 'Masuga', 'Madara', 'Nagwamatse', 'Rafin Gora', 'Tunganwawa', 'Tungan Kawo', 'Usalle', 'Yamma']],
        ['12', 'Lapai', 9.05, 6.57, 0.52, 0.34, ['Arewa/Yamma', 'Birnin Maza/Tashibo', 'Ebbo/Gbacinku', 'Evuti/Kpada', 'Gulu/Anguwa Vatsa', 'Gupa/Abugi', 'Gurdi/Zago', 'Kudu/Gabas', 'Muye/Egba', 'Takuti/Shaku']],
        ['13', 'Lavun', 9.20, 5.60, 0.50, 0.35, ['Busu/Kuchi', 'Batati', 'Dassun', 'Doko', 'Dabban', 'Egbako', 'Gaba', 'Jima', 'Kutigi', 'Kusotachi', 'Lagun', 'Mambe']],
        ['14', 'Magama', 10.33, 5.10, 0.40, 0.44, ['Auna Central', 'Auna East Central', 'Auna East', 'Auna South East', 'Auna South', 'Ibelu Central', 'Ibelu East', 'Ibelu North', 'Ibelu West', 'Nasko', 'Nassarawa']],
        ['15', 'Mariga', 10.75, 5.75, 0.42, 0.43, ['Bangi', 'Beri', 'Bobi', 'Gulbi Koka', 'Galma', 'Inkwai', 'Igwama', 'Kakihum', 'Kontokoro', 'Kumbashi', 'Maburya']],
        ['16', 'Mashegu', 9.97, 5.78, 0.47, 0.38, ['Babban Rami', 'Dapangi/Makera', 'Ibbi', 'Kaboji', 'Kasanga', 'Kwatachi', 'Kulho', 'Mashegu', 'Mazakuka', 'Saho Rami']],
        ['17', 'Mokwa', 9.29, 5.05, 0.44, 0.40, ['Bokani', 'Gbajibo/Muwo', 'Gbara', 'Jaagi', 'Jeba North', 'Kpaki', 'Kudu', 'Labozhi', 'Mokwa', 'Muregi', 'Rabban/Dayahko']],
        ['18', 'Munya', 10.02, 7.10, 0.36, 0.48, ['Beni', 'Dandaudu', 'Dangunu', 'Daza', 'Fuka', 'Gini', 'Guni', 'Kabula', 'Kazai', 'Kuchi', 'Sarki Pawa']],
        ['19', 'Paikoro', 9.44, 6.63, 0.50, 0.35, ['Aduni', 'Chimbi', 'Gwam', 'Ishau', 'Jere', 'Kafin Koro', 'Kwagana', 'Kwakuti', 'Nikuchi T', 'Paikoro Central', 'Tutungo Jedna']],
        ['20', 'Rafi', 10.19, 6.25, 0.46, 0.39, ['Kagara Gari', 'Kakuri', 'Kongoma Central', 'Kongoma East', 'Kongoma North', 'Kongoma South', 'Kundun', 'Sabon Gari', 'Tegina Gari', 'Tegina West', 'Yakila']],
        ['21', 'Rijau', 11.10, 5.25, 0.21, 0.62, ['Danrangi', 'Dugge', 'Dukku', 'Genu', 'Jama\'are', 'Rijau', 'Shambo', 'T/Bunu', 'T/Magajiya', 'Uche', 'Warari']],
        ['22', 'Shiroro', 9.87, 6.71, 0.41, 0.44, ['Allawa', 'Bangajiya', 'Bassa/Kukoki', 'Egwa/Gwada', 'Erana', 'Galkogo', 'Gurmana', 'Gussoro', 'Kato', 'Kushaka', 'Kwaki', 'Manta', 'Pina', 'She', 'Ubandoma']],
        ['23', 'Suleja', 9.18, 7.18, 0.31, 0.53, ['Bagmama A', 'Bagmama B', 'Hashimi A', 'Hashimi B', 'Iku South I', 'Iku South II', 'Kurmin Sarki', 'Magajiya', 'Maje North', 'Wambai']],
        ['24', 'Tafa', 9.30, 7.23, 0.27, 0.56, ['Dogon', 'Garam', 'Ija Gwari', 'Ija Koro', 'Iku', 'New Bwari', 'Wuse West', 'Wuse East', 'Zuma East', 'Zuma West']],
        ['25', 'Wushishi', 9.73, 6.07, 0.48, 0.37, ['Akare', 'Barwa', 'Gwarjiko', 'Kanwari', 'Kodo', 'Kwata', 'Lokogoma', 'Maito', 'Sabon Gari', 'Tukunji Yamigi', 'Zungeru']],
    ];

    private const PLACES = ['LEA Primary School', 'Village Square', 'Health Centre', 'Market Square', 'Town Hall', 'Junior Secondary School', 'Community Hall', 'Motor Park', 'Central Mosque Junction', 'Church Premises'];

    private const FIRST = ['Aisha', 'Musa', 'Fatima', 'Ibrahim', 'Zainab', 'Usman', 'Hauwa', 'Yusuf', 'Halima', 'Sani', 'Grace', 'Daniel', 'Amina', 'Emeka', 'Ngozi', 'Tunde', 'Bola', 'Joseph', 'Hadiza', 'Abubakar', 'Rukayya', 'Peter', 'Maryam', 'Kabiru'];

    private const LAST = ['Abdullahi', 'Bello', 'Mohammed', 'Danjuma', 'Okafor', 'Adeyemi', 'Yakubu', 'Garba', 'Suleiman', 'Ibrahim', 'Nwosu', 'Ahmed', 'Aliyu', 'Ogunleye', 'Haruna', 'Isah'];

    private const INCIDENTS = [
        ['agent_turned_away', 'high', 'Presiding officer refused entry without a new letter. Supervisor on the way.', '07:40'],
        ['materials_late', 'low', 'Materials arrived at 9:15; voting started late.', '09:15'],
        ['bvas_problem', 'medium', 'BVAS stopped reading fingerprints for about 40 minutes. Voters waited.', '10:20'],
        ['vote_buying', 'high', 'Men near the queue handing out cash to voters. Photos attached.', '11:05'],
        ['bvas_problem', 'medium', 'BVAS battery died; replacement arrived after an hour.', '12:10'],
        ['vote_buying', 'medium', 'Voters asked to show marked ballots in exchange for food items.', '12:45'],
        ['disruption', 'high', 'Thugs scattered the queue and took a ballot box. Police called.', '13:30'],
        ['other', 'low', 'Rain; voting moved under the school veranda.', '14:00'],
        ['disruption', 'medium', 'Argument between party supporters; security restored order after 20 minutes.', '14:25'],
        ['agent_turned_away', 'medium', 'Agent asked to stand outside the unit during counting.', '16:05'],
        ['materials_late', 'medium', 'Result sheets ran short; presiding officer sent for more.', '16:40'],
        ['other', 'low', 'Power out at the unit; counting finished by torchlight.', '18:10'],
    ];

    private Campaign $campaign;

    /** @var array<int, string> */
    private array $secrets = [];

    private int $people = 0;

    private int $perWard;

    public function run(): void
    {
        mt_srand(2027);
        $this->perWard = max(3, (int) env('PITCH_DEMO_UNITS', 6));

        $this->campaign = Campaign::create([
            'name' => 'Demo Governorship Campaign',
            'state_name' => 'Niger',
            'election_date' => '2027-02-06',
            'freeze_at' => '2027-01-30',
        ]);

        foreach ([['A', 'Party A', true], ['B', 'Party B', false], ['C', 'Party C', false], ['D', 'Party D', false]] as $i => [$code, $name, $ours]) {
            Party::create(['campaign_id' => $this->campaign->id, 'code' => $code, 'name' => $name, 'is_ours' => $ours, 'sort_order' => $i]);
        }

        foreach ([Role::Dg, Role::SituationRoom, Role::Verifier, Role::DataOfficer, Role::Legal, Role::Finance] as $role) {
            User::factory()->role($role)->create(['campaign_id' => $this->campaign->id, 'name' => $role->label(), 'email' => $role->value.'@demo.test']);
        }

        $agentNo = 0;
        foreach (self::LGAS as [$lc, $lname, $lat, $lng, , , $wardNames]) {
            $lga = Lga::create(['campaign_id' => $this->campaign->id, 'code' => "LGA-{$lc}", 'name' => $lname]);
            $this->field(Role::LgaSupervisor, ['lga_id' => $lga->id], 'SV-'.$lc);
            foreach ($wardNames as $wi => $wardName) {
                $w = $wi + 1;
                $ward = Ward::create(['campaign_id' => $this->campaign->id, 'lga_id' => $lga->id, 'code' => sprintf('W%02d', $w), 'name' => $wardName]);
                $this->field(Role::WardSupervisor, ['lga_id' => $lga->id, 'ward_id' => $ward->id], sprintf('WS-%s%02d', $lc, $w));
                $this->field(Role::CollationAgent, ['lga_id' => $lga->id, 'ward_id' => $ward->id], sprintf('CA-%s%02d', $lc, $w));
                $wlat = $lat + (intdiv($w - 1, 4) - 1.5) * 0.03;
                $wlng = $lng + (($w - 1) % 4 - 1.5) * 0.03;
                for ($p = 1; $p <= $this->perWard; $p++) {
                    $unit = PollingUnit::create([
                        'campaign_id' => $this->campaign->id,
                        'ward_id' => $ward->id,
                        'code' => sprintf('%s-%02d-%03d', $lc, $w, $p),
                        'name' => trim(preg_replace("/[\\/'].*$|\\s+I+$/", '', $wardName)).' '.self::PLACES[($p + $w) % count(self::PLACES)].($p > count(self::PLACES) ? ' II' : ''),
                        'latitude' => round($wlat + (($p - 1) % 4 - 1.5) * 0.003, 6),
                        'longitude' => round($wlng + (intdiv($p - 1, 4) - 1) * 0.003, 6),
                        'registered_voters' => 500 + mt_rand(0, 14) * 50,
                    ]);
                    $agentNo++;
                    $agent = $this->field(Role::Agent, ['polling_unit_id' => $unit->id, 'ward_id' => $ward->id], sprintf('AG-%05d', $agentNo));
                    $ready = $agentNo % 17 !== 0;
                    $agent->update([
                        'invited_at' => now()->subDays(12),
                        'practice_passed_at' => $ready ? now()->subDays(7) : null,
                        'quiz_score' => $ready ? 7 + $agentNo % 4 : null,
                        'trained_at' => $ready ? now()->subDays(6) : null,
                        'confirmed_at' => $ready && $agentNo % 9 !== 0 ? now()->subDay() : null,
                    ]);
                }
            }
        }

        $this->electionDay();
    }

    private function field(Role $role, array $attrs, string $code): User
    {
        $this->people++;
        $name = self::FIRST[$this->people % count(self::FIRST)].' '.self::LAST[($this->people * 7) % count(self::LAST)];

        return User::factory()->role($role)->create($attrs + [
            'campaign_id' => $this->campaign->id,
            'name' => $name,
            'code' => $code,
            'phone' => sprintf('0803%07d', $this->people),
        ]);
    }

    private function electionDay(): void
    {
        $units = PollingUnit::where('campaign_id', $this->campaign->id)->with('ward.lga')->orderBy('code')->get()->values();
        $n = $units->count();
        $u = $this->perWard;
        $agentFor = fn (PollingUnit $unit) => User::where('polling_unit_id', $unit->id)->where('role', Role::Agent->value)->firstOrFail();
        $at = fn (string $hm) => $this->campaign->election_date->format('Y-m-d')."T{$hm}:00Z";
        $hm = fn (int $minutes) => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        $lgaShare = collect(self::LGAS)->keyBy(fn ($l) => "LGA-{$l[0]}");

        $verifier = $this->staff(Role::Verifier);
        $officer = $this->staff(Role::DataOfficer);
        $room = $this->staff(Role::SituationRoom);

        // Wards still waiting for some units, and wards missing one; Chanchaga (LGA-06) is complete.
        $slow = fn (int $w, PollingUnit $unit) => $w % 5 === 1 && $unit->ward->lga->code !== 'LGA-06';
        $oneShort = fn (int $w, PollingUnit $unit) => $w % 7 === 3 && ! $slow($w, $unit) && $unit->ward->lga->code !== 'LGA-06';
        $overvote = $units->search(fn ($unit) => $unit->code === '03-01-005');
        $overvote = $overvote === false ? min(40, $n - 1) : $overvote;

        $figures = [];
        $rejected = [];
        foreach ($units as $i => $unit) {
            $agent = $agentFor($unit);
            $w = intdiv($i, $u);
            $p = $i % $u + 1;
            // In the slow wards the last agent never checked in; one agent in 25 checks in outside the unit.
            if ($slow($w, $unit) && $p === $u) {
                continue;
            }
            $outside = $i % 25 === 7;
            $checkIn = $at($hm(7 * 60 + 5 + ($i * 7) % 110));
            $this->clock($checkIn, 1);
            $this->send($agent, 'POST', 'check-ins', [
                'latitude' => $unit->latitude + ($outside ? 0.006 : 0.0002),
                'longitude' => $unit->longitude,
                'accuracy_m' => $outside ? 22 : 6 + $i % 8,
                'captured_at' => $checkIn,
                'request_review' => $outside,
            ]);

            if (($slow($w, $unit) && in_array($p, [2, $u - 1], true)) || ($oneShort($w, $unit) && $p === 4)) {
                continue;
            }
            [, , , , $ours, $rival] = $lgaShare[$unit->ward->lga->code];
            $acc = (int) round($unit->registered_voters * (0.32 + mt_rand(0, 14) / 100));
            $rej = mt_rand(4, 14);
            $valid = $acc - $rej - mt_rand(0, 6);
            $a = (int) round($valid * max(0.05, $ours + (mt_rand(-80, 80) / 1000)));
            $b = (int) round($valid * max(0.05, $rival + (mt_rand(-80, 80) / 1000)));
            $c = (int) round(($valid - $a - $b) * 0.7);
            $votes = ['A' => $a, 'B' => $b, 'C' => max(0, $c), 'D' => max(0, $valid - $a - $b - $c)];
            if ($i === $overvote) {
                $votes['B'] += 160; // over-voting: more votes than accredited voters
            }
            $figures[$i] = $votes;
            $rejected[$i] = $rej;
            $captured = $at($hm(15 * 60 + intdiv($i * 330, max(1, $n))));
            $this->clock($captured, 2);
            $id = $this->submitResult($agent, $unit, $votes, $acc, $rej, $captured);
            if ($i % 3 !== 2 && $i !== $overvote) {
                $this->clock($captured, 25);
                $this->send($verifier, 'POST', "results/{$id}/approve", []);
            }
        }

        // Incidents spread across the state through the day.
        foreach (self::INCIDENTS as $k => [$type, $severity, $text, $time]) {
            $i = (int) floor(($k + 0.5) * $n / count(self::INCIDENTS));
            $this->clock($at($time), 3);
            $this->send($agentFor($units[$i]), 'POST', 'incidents', ['type' => $type, 'severity' => $severity, 'description' => $text, 'occurred_at' => $at($time)]);
        }

        // Ward collation: every complete ward plus two still waiting for units; some declared sheets don't add up.
        $complete = 0;
        $partial = 0;
        foreach ($units->groupBy('ward_id') as $wardId => $wardUnits) {
            $first = $wardUnits->first();
            $w = intdiv($units->search($first), $u);
            $isComplete = ! $slow($w, $first) && ! $oneShort($w, $first);
            if (! $isComplete && ++$partial > 2) {
                continue;
            }
            [$sum, $rej] = $this->add($units, $figures, $rejected, fn ($x) => $x->ward_id === $wardId);
            if ($isComplete) {
                $complete++;
                if ($complete === 3) {
                    $sum['B'] += 212;
                    $sum['A'] -= 95;
                } elseif ($complete === 8) {
                    $sum['B'] += 74;
                } elseif ($complete % 40 === 0) {
                    $sum['B'] += 30 + $complete % 60;
                }
            }
            $announced = $at($hm(19 * 60 + intdiv($w * 240, max(1, intdiv($n, $u)))));
            $this->clock($announced, 15);
            $this->send($officer, 'POST', 'collation-entries', ['level' => 'ward', 'scope_id' => $wardId, 'votes' => $sum, 'rejected' => $rej, 'announced_at' => $announced]);
        }

        // Chanchaga LGA collated, matching.
        $lga = Lga::where('campaign_id', $this->campaign->id)->where('code', 'LGA-06')->first();
        [$sum, $rej] = $this->add($units, $figures, $rejected, fn ($x) => $x->ward->lga_id === $lga->id);
        $this->clock($at('23:10'), 12);
        $this->send($room, 'POST', 'collation-entries', ['level' => 'lga', 'scope_id' => $lga->id, 'votes' => $sum, 'rejected' => $rej, 'announced_at' => $at('23:10')]);

        // IReV: the first units plus a sample across the state; a few differ, a few are missing.
        $sample = array_values(array_unique(array_merge([0, 1, 2, 4, 6, 8, 9, 12, 14, 18, 20, 22, 24, 26], range(30, $n - 1, 15))));
        foreach ($sample as $k => $i) {
            if (! isset($figures[$i])) {
                continue;
            }
            $v = $figures[$i];
            if ($k === 3 || $k % 25 === 20) {
                $v['B'] += 120;
            } elseif ($k === 8) {
                $v['A'] -= 60;
            }
            $seen = $at($hm(17 * 60 + 30 + intdiv($k * 240, max(1, count($sample)))));
            $this->clock($seen, 1);
            $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[$i]->id, 'not_on_irev' => false, 'votes' => $v, 'seen_at' => $seen]);
        }
        foreach (range(31, $n - 1, 200) as $k => $i) {
            $seen = $at($hm(18 * 60 + 50 + $k));
            $this->clock($seen, 1);
            $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[$i]->id, 'not_on_irev' => true, 'seen_at' => $seen]);
        }
        Carbon::setTestNow();
    }

    /** Records each step as if it happened on election day, a few minutes after the event. */
    private function clock(string $iso, int $minutesLater): void
    {
        Carbon::setTestNow(Carbon::parse($iso)->addMinutes($minutesLater));
    }

    /** Sums our signed figures for the units that match. */
    private function add($units, array $figures, array $rejected, callable $match): array
    {
        $sum = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $rej = 0;
        foreach ($units as $i => $u) {
            if ($match($u) && isset($figures[$i])) {
                foreach ($figures[$i] as $party => $n) {
                    $sum[$party] += $n;
                }
                $rej += $rejected[$i];
            }
        }

        return [$sum, $rej];
    }

    private function staff(Role $role): User
    {
        return User::where('campaign_id', $this->campaign->id)->where('role', $role->value)->firstOrFail();
    }

    private function submitResult(User $agent, PollingUnit $unit, array $votes, int $acc, int $rej, string $capturedAt): int
    {
        if (! isset($this->secrets[$agent->id])) {
            $pair = sodium_crypto_sign_keypair();
            $this->secrets[$agent->id] = sodium_crypto_sign_secretkey($pair);
            Device::create(['user_id' => $agent->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        }
        $device = Device::where('user_id', $agent->id)->latest('id')->first();
        $lat = (float) $unit->latitude;
        $lng = (float) $unit->longitude;
        $photo = $this->sheet($unit, $votes, $acc, $rej);
        $sha = $photo ? hash_file('sha256', $photo) : null;
        $canonical = ResultSigner::canonical($unit->code, $acc, $rej, $votes, $sha, $lat, $lng, $capturedAt, null);

        $body = [
            'device_id' => $device->id,
            'signature' => base64_encode(sodium_crypto_sign_detached($canonical, $this->secrets[$agent->id])),
            'captured_at' => $capturedAt,
            'accredited' => $acc,
            'rejected' => $rej,
            'votes' => $votes,
            'latitude' => $lat,
            'longitude' => $lng,
            'photo_sha256' => $sha,
        ];

        $id = $this->send($agent, 'POST', 'results', $body, $photo ? ['photo' => new UploadedFile($photo, 'ec8a.jpg', 'image/jpeg', null, true)] : [])['id'];
        if ($photo && is_file($photo)) {
            @unlink($photo);
        }

        return $id;
    }

    /**
     * Draws a phone photo of a made-up result sheet, plainly marked as a demo (not an INEC form),
     * so the verify and evidence screens have a picture to show. Skipped without GD.
     */
    private function sheet(PollingUnit $unit, array $votes, int $acc, int $rej): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        // DejaVu fonts ship beside this seeder, so shared hosting without system fonts still gets photos.
        $font = collect([__DIR__.'/fonts/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'])->first(fn ($f) => is_file($f));
        $hand = collect([__DIR__.'/fonts/DejaVuSansMono-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf'])->first(fn ($f) => is_file($f)) ?? $font;
        if (! $font || ! function_exists('imagettftext')) {
            return null;
        }
        $w = 900;
        $h = 1200;
        $paper = imagecreatetruecolor($w, $h);
        imagefill($paper, 0, 0, imagecolorallocate($paper, 246, 243, 232));
        $ink = imagecolorallocate($paper, 40, 40, 44);
        $faint = imagecolorallocate($paper, 150, 146, 136);
        $pen = imagecolorallocate($paper, 22, 46, 140);
        $red = imagecolorallocate($paper, 180, 40, 30);
        imagesetthickness($paper, 2);

        imagettftext($paper, 30, 0, 60, 90, $ink, $font, 'STATEMENT OF RESULT');
        imagettftext($paper, 16, 0, 60, 128, $red, $font, 'DEMO SHEET for PollProof demonstrations. Not an INEC form.');
        imageline($paper, 60, 150, $w - 60, 150, $ink);
        $y = 200;
        foreach (['State' => 'Niger (demo)', 'LGA' => $unit->ward->lga->name, 'Ward' => $unit->ward->name, 'Polling unit' => $unit->code.'  '.$unit->name] as $label => $value) {
            imagettftext($paper, 18, 0, 60, $y, $faint, $font, $label);
            imagettftext($paper, 22, 0, 290, $y, $pen, $hand, mb_strimwidth($value, 0, 34, ''));
            imageline($paper, 280, $y + 10, $w - 60, $y + 10, $faint);
            $y += 56;
        }
        $y += 30;
        imagerectangle($paper, 60, $y, $w - 60, $y + 64 * 7, $ink);
        $rows = ['Accredited voters' => $acc];
        foreach ($votes as $party => $n) {
            $rows["Party {$party}"] = $n;
        }
        $rows['Rejected ballots'] = $rej;
        $rows['Total votes cast'] = array_sum($votes) + $rej;
        foreach (array_values(array_keys($rows)) as $k => $label) {
            $ry = $y + $k * 64;
            if ($k > 0) {
                imageline($paper, 60, $ry, $w - 60, $ry, $ink);
            }
            imagettftext($paper, 20, 0, 80, $ry + 42, $ink, $font, $label);
            imagettftext($paper, 30, mt_rand(-2, 2), 560, $ry + 46, $pen, $hand, number_format($rows[$label]));
        }
        imageline($paper, 520, $y, 520, $y + 64 * 7, $ink);
        $sy = $y + 64 * 7 + 90;
        foreach (['Presiding officer', 'Party agents'] as $k => $label) {
            imagettftext($paper, 16, 0, 60 + $k * 400, $sy + 50, $faint, $font, $label);
            for ($i = 0; $i < 4; $i++) {
                imagearc($paper, 120 + $k * 400 + $i * 40, $sy, 70, 40 + mt_rand(0, 20), 180, 360, $pen);
            }
        }

        // The paper, slightly turned, on a table: what an agent's photo looks like.
        $turned = imagerotate($paper, mt_rand(-25, 25) / 10, imagecolorallocate($paper, 92, 70, 52));
        $photo = imagecreatetruecolor(960, 1280);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 92, 70, 52));
        imagecopy($photo, $turned, (int) ((960 - imagesx($turned)) / 2), (int) ((1280 - imagesy($turned)) / 2), 0, 0, imagesx($turned), imagesy($turned));
        $small = imagescale($photo, 720, 960);
        $path = sys_get_temp_dir().'/pollproof-ec8a-'.bin2hex(random_bytes(6)).'.jpg';
        imagejpeg($small, $path, 72);

        return $path;
    }

    private function send(User $user, string $method, string $path, array $body, array $files = []): array
    {
        $token = $user->createToken('demo')->plainTextToken;
        $server = ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
        $request = $files
            ? Request::create("/api/v1/{$path}", $method, array_filter($body, fn ($v) => $v !== null), [], $files, $server)
            : Request::create("/api/v1/{$path}", $method, server: $server + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));

        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        app('auth')->forgetGuards();
        $user->tokens()->where('name', 'demo')->delete();

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException("{$method} {$path} as {$user->code}: {$response->getStatusCode()} {$response->getContent()}");
        }

        return json_decode($response->getContent(), true) ?? [];
    }
}
