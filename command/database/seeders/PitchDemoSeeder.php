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
 * A larger, made-up state for demos and pitch screenshots: 8 LGAs, 24 wards, 144 polling units,
 * then election day played through the real API (check-ins, signed results, incidents,
 * collation and IReV). All names and figures are invented.
 *
 *   php artisan migrate:fresh --seed --seeder=PitchDemoSeeder
 *
 * Command-room users sign in as <role>@demo.test with password "password".
 */
class PitchDemoSeeder extends Seeder
{
    private const LGAS = [
        // code, name, centre lat, lng, our share, main rival share
        ['01', 'Northgate', 9.70, 6.40, 0.52, 0.33],
        ['02', 'Riverbend', 9.62, 6.62, 0.47, 0.38],
        ['03', 'Hilltop', 9.52, 6.48, 0.58, 0.28],
        ['04', 'Lakeside', 9.44, 6.70, 0.36, 0.49],
        ['05', 'Eastfield', 9.36, 6.52, 0.49, 0.35],
        ['06', 'Westbrook', 9.80, 6.58, 0.21, 0.62],
        ['07', 'Southpoint', 9.28, 6.36, 0.44, 0.40],
        ['08', 'Central', 9.58, 6.30, 0.55, 0.31],
    ];

    private const PLACES = ['LEA Primary School', 'Village Square', 'Health Centre', 'Market Square', 'Town Hall', 'Junior Secondary School'];

    private const FIRST = ['Aisha', 'Musa', 'Fatima', 'Ibrahim', 'Zainab', 'Usman', 'Hauwa', 'Yusuf', 'Halima', 'Sani', 'Grace', 'Daniel', 'Amina', 'Emeka', 'Ngozi', 'Tunde', 'Bola', 'Joseph', 'Hadiza', 'Abubakar', 'Rukayya', 'Peter', 'Maryam', 'Kabiru'];

    private const LAST = ['Abdullahi', 'Bello', 'Mohammed', 'Danjuma', 'Okafor', 'Adeyemi', 'Yakubu', 'Garba', 'Suleiman', 'Ibrahim', 'Nwosu', 'Ahmed', 'Aliyu', 'Ogunleye', 'Haruna', 'Isah'];

    private Campaign $campaign;

    /** @var array<int, string> */
    private array $secrets = [];

    private int $people = 0;

    public function run(): void
    {
        mt_srand(2027);

        $this->campaign = Campaign::create([
            'name' => 'Demo Governorship Campaign',
            'state_name' => 'Demo State',
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
        foreach (self::LGAS as [$lc, $lname, $lat, $lng]) {
            $lga = Lga::create(['campaign_id' => $this->campaign->id, 'code' => "LGA-{$lc}", 'name' => $lname]);
            $this->field(Role::LgaSupervisor, ['lga_id' => $lga->id], 'SV-'.$lc);
            foreach ([1, 2, 3] as $w) {
                $ward = Ward::create(['campaign_id' => $this->campaign->id, 'lga_id' => $lga->id, 'code' => sprintf('W%02d', $w), 'name' => "{$lname} Ward {$w}"]);
                $this->field(Role::WardSupervisor, ['lga_id' => $lga->id, 'ward_id' => $ward->id], sprintf('WS-%s%02d', $lc, $w));
                $this->field(Role::CollationAgent, ['lga_id' => $lga->id, 'ward_id' => $ward->id], sprintf('CA-%s%02d', $lc, $w));
                foreach (range(1, 6) as $p) {
                    $unit = PollingUnit::create([
                        'campaign_id' => $this->campaign->id,
                        'ward_id' => $ward->id,
                        'code' => sprintf('%s-%02d-%03d', $lc, $w, $p),
                        'name' => self::PLACES[($p + $w) % 6].' '.['I', 'II', 'III'][$p % 3],
                        'latitude' => $lat + ($w - 2) * 0.03 + ($p - 3) * 0.006,
                        'longitude' => $lng + ($w - 2) * 0.025 - ($p - 3) * 0.004,
                        'registered_voters' => 600 + mt_rand(0, 9) * 60,
                    ]);
                    $agentNo++;
                    $agent = $this->field(Role::Agent, ['polling_unit_id' => $unit->id, 'ward_id' => $ward->id], sprintf('AG-%04d', $agentNo));
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
        $agentFor = fn (PollingUnit $u) => User::where('polling_unit_id', $u->id)->where('role', Role::Agent->value)->firstOrFail();
        $at = fn (string $hm) => $this->campaign->election_date->format('Y-m-d')."T{$hm}:00Z";
        $lgaShare = collect(self::LGAS)->keyBy(fn ($l) => "LGA-{$l[0]}");

        $verifier = $this->staff(Role::Verifier);
        $officer = $this->staff(Role::DataOfficer);
        $room = $this->staff(Role::SituationRoom);

        // Wards still waiting for some units (by position in the list), and wards missing one unit.
        $slowWards = [1, 4, 9, 10, 14, 17, 20, 22];
        $oneMissing = [5, 13, 19];
        $figures = [];
        $rejected = [];
        foreach ($units as $i => $unit) {
            $agent = $agentFor($unit);
            $w = intdiv($i, 6);
            $p = $i % 6 + 1;
            // In the slow wards the sixth agent never checked in; one agent in 25 checks in outside the unit.
            if (in_array($w, $slowWards, true) && $p === 6) {
                continue;
            }
            $outside = $i % 25 === 7;
            $this->clock($at(sprintf('07:%02d', 5 + $i % 50)), 1);
            $this->send($agent, 'POST', 'check-ins', [
                'latitude' => $unit->latitude + ($outside ? 0.006 : 0.0002),
                'longitude' => $unit->longitude,
                'accuracy_m' => $outside ? 22 : 6 + $i % 8,
                'captured_at' => $at(sprintf('07:%02d', 5 + $i % 50)),
                'request_review' => $outside,
            ]);

            if ((in_array($w, $slowWards, true) && in_array($p, [2, 5], true)) || (in_array($w, $oneMissing, true) && $p === 4)) {
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
            if ($i === 40) {
                $votes['B'] += 160; // over-voting: more votes than accredited voters
            }
            $figures[$i] = $votes;
            $rejected[$i] = $rej;
            $captured = $at(sprintf('%02d:%02d', 15 + intdiv($i, 40), ($i * 7) % 60));
            $this->clock($captured, 2);
            $id = $this->submitResult($agent, $unit, $votes, $acc, $rej, $captured);
            if ($i % 3 !== 2 && $i !== 40) {
                $this->clock($captured, 25);
                $this->send($verifier, 'POST', "results/{$id}/approve", []);
            }
        }

        // Incidents across the day.
        $incidents = [
            [3, 'agent_turned_away', 'high', 'Presiding officer refused entry without a new letter. Supervisor on the way.', '07:40'],
            [17, 'bvas_problem', 'medium', 'BVAS stopped reading fingerprints for about 40 minutes. Voters waited.', '10:20'],
            [29, 'vote_buying', 'high', 'Men near the queue handing out cash to voters. Photos attached.', '11:05'],
            [52, 'materials_late', 'low', 'Materials arrived at 9:15; voting started late.', '09:15'],
            [66, 'disruption', 'high', 'Thugs scattered the queue and took a ballot box. Police called.', '13:30'],
            [88, 'bvas_problem', 'medium', 'BVAS battery died; replacement arrived after an hour.', '12:10'],
            [101, 'vote_buying', 'medium', 'Voters asked to show marked ballots in exchange for food items.', '12:45'],
            [130, 'other', 'low', 'Rain; voting moved under the school veranda.', '14:00'],
        ];
        foreach ($incidents as [$i, $type, $severity, $text, $time]) {
            $this->clock($at($time), 3);
            $this->send($agentFor($units[$i]), 'POST', 'incidents', ['type' => $type, 'severity' => $severity, 'description' => $text, 'occurred_at' => $at($time)]);
        }

        // Ward collation: every complete ward plus two still waiting for units; two declared sheets don't add up.
        $complete = 0;
        foreach ($units->groupBy('ward_id') as $wardId => $wardUnits) {
            $w = intdiv($units->search($wardUnits->first()), 6);
            $isComplete = ! in_array($w, $slowWards, true) && ! in_array($w, $oneMissing, true);
            if (! $isComplete && ! in_array($w, [4, 13], true)) {
                continue;
            }
            [$sum, $rej] = $this->add($units, $figures, $rejected, fn ($u) => $u->ward_id === $wardId);
            if ($isComplete) {
                $complete++;
                if ($complete === 3) {
                    $sum['B'] += 212;
                    $sum['A'] -= 95;
                }
                if ($complete === 8) {
                    $sum['B'] += 74;
                }
            }
            $this->clock($at(sprintf('%02d:%02d', 19 + intdiv($w, 8), ($w * 7) % 60)), 15);
            $this->send($officer, 'POST', 'collation-entries', ['level' => 'ward', 'scope_id' => $wardId, 'votes' => $sum, 'rejected' => $rej, 'announced_at' => $at(sprintf('%02d:%02d', 19 + intdiv($w, 8), ($w * 7) % 60))]);
        }

        // Hilltop LGA collated, matching.
        $lga = Lga::where('campaign_id', $this->campaign->id)->where('code', 'LGA-03')->first();
        [$sum, $rej] = $this->add($units, $figures, $rejected, fn ($u) => $u->ward->lga_id === $lga->id);
        $this->clock($at('23:10'), 12);
        $this->send($room, 'POST', 'collation-entries', ['level' => 'lga', 'scope_id' => $lga->id, 'votes' => $sum, 'rejected' => $rej, 'announced_at' => $at('23:10')]);

        // IReV: a sample checked; two differ, one missing.
        foreach ([0, 1, 2, 4, 6, 8, 9, 12, 14, 18, 20, 22, 24, 26] as $k => $i) {
            if (! isset($figures[$i])) {
                continue;
            }
            $v = $figures[$i];
            if ($k === 3) {
                $v['B'] += 120;
            }
            if ($k === 8) {
                $v['A'] -= 60;
            }
            $this->clock($at(sprintf('18:%02d', $k * 3)), 1);
            $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[$i]->id, 'not_on_irev' => false, 'votes' => $v, 'seen_at' => $at(sprintf('18:%02d', $k * 3))]);
        }
        $this->clock($at('18:50'), 1);
        $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[30]->id, 'not_on_irev' => true, 'seen_at' => $at('18:50')]);
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

        return $this->send($agent, 'POST', 'results', $body, $photo ? ['photo' => new UploadedFile($photo, 'ec8a.jpg', 'image/jpeg', null, true)] : [])['id'];
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
        $font = collect(['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'])->first(fn ($f) => is_file($f));
        $hand = collect(['/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf'])->first(fn ($f) => is_file($f)) ?? $font;
        if (! $font) {
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
        foreach (['State' => 'Demo State', 'LGA' => $unit->ward->lga->name, 'Ward' => $unit->ward->name, 'Polling unit' => $unit->code.'  '.$unit->name] as $label => $value) {
            imagettftext($paper, 18, 0, 60, $y, $faint, $font, $label);
            imagettftext($paper, 22, 0, 290, $y, $pen, $hand, $value);
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
        $path = tempnam(sys_get_temp_dir(), 'ec8a').'.jpg';
        imagejpeg($photo, $path, 78);

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
