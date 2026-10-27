<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Device;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\ResultSigner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Plays a short election day against the demo campaign through the real API, so the
 * command room has check-ins, signed results, a correction, incidents, a collation
 * mismatch and IReV checks to show. Run after DatabaseSeeder:
 *
 *   php artisan db:seed --class=ElectionDaySeeder
 */
class ElectionDaySeeder extends Seeder
{
    private Campaign $campaign;

    /** @var array<int, string> device secret keys by agent id, kept only for this run */
    private array $secrets = [];

    public function run(): void
    {
        $this->campaign = Campaign::firstOrFail();
        $units = PollingUnit::where('campaign_id', $this->campaign->id)->orderBy('code')->get()->values();
        $agent = fn (PollingUnit $u) => User::where('polling_unit_id', $u->id)->where('role', Role::Agent->value)->firstOrFail();
        $at = fn (string $hm) => $this->campaign->election_date->format('Y-m-d')."T{$hm}:00Z";

        // Check-ins: units 1–9 on site, unit 10 outside the area and asking for review, 11–12 absent.
        foreach ($units->take(10) as $i => $unit) {
            $outside = $i === 9;
            $this->send($agent($unit), 'POST', 'check-ins', [
                'latitude' => $unit->latitude + ($outside ? 0.004 : 0.0002),
                'longitude' => $unit->longitude,
                'accuracy_m' => $outside ? 18 : 8,
                'captured_at' => $at(sprintf('07:%02d', 10 + $i * 3)),
                'request_review' => $outside,
            ]);
        }

        // Signed results for units 1–8.
        $figures = [
            [412, ['A' => 221, 'B' => 140, 'C' => 31, 'D' => 9], 11],
            [388, ['A' => 175, 'B' => 182, 'C' => 20, 'D' => 4], 7],
            [455, ['A' => 260, 'B' => 151, 'C' => 28, 'D' => 6], 10],
            [301, ['A' => 120, 'B' => 149, 'C' => 18, 'D' => 5], 9],
            [366, ['A' => 199, 'B' => 120, 'C' => 30, 'D' => 8], 9],
            [290, ['A' => 151, 'B' => 101, 'C' => 22, 'D' => 4], 12],
            [340, ['A' => 168, 'B' => 140, 'C' => 19, 'D' => 3], 10],
            [410, ['A' => 230, 'B' => 140, 'C' => 25, 'D' => 5], 10],
        ];
        $ids = [];
        foreach ($figures as $i => [$acc, $votes, $rej]) {
            $ids[$i] = $this->submitResult($agent($units[$i]), $units[$i], $votes, $acc, $rej, $at(sprintf('15:%02d', 5 + $i * 4)));
        }

        // Unit 5's agent typed B wrongly and sends a correction.
        $this->submitResult($agent($units[4]), $units[4], ['A' => 199, 'B' => 112, 'C' => 30, 'D' => 8], 366, 9, $at('16:10'), $ids[4], 'B was typed as 120; the sheet says 112');

        // A verifier approves the first three.
        $verifier = User::where('campaign_id', $this->campaign->id)->where('role', Role::Verifier->value)->firstOrFail();
        foreach (array_slice($ids, 0, 3) as $id) {
            $this->send($verifier, 'POST', "results/{$id}/approve", []);
        }

        // Incidents.
        $this->send($agent($units[10]), 'POST', 'incidents', ['type' => 'agent_turned_away', 'severity' => 'high', 'description' => 'Presiding officer would not let me into the unit without a new letter.', 'occurred_at' => $at('08:05')]);
        $this->send($agent($units[3]), 'POST', 'incidents', ['type' => 'bvas_problem', 'severity' => 'medium', 'description' => 'BVAS stopped reading fingerprints for about 40 minutes. Voters waited.', 'occurred_at' => $at('10:20')]);
        $this->send($agent($units[6]), 'POST', 'incidents', ['type' => 'materials_late', 'severity' => 'low', 'description' => 'Materials arrived at 9:15.', 'occurred_at' => $at('09:15')]);

        // Ward 1 collation: B declared 40 higher than the units add up to.
        $collator = User::where('campaign_id', $this->campaign->id)->where('role', Role::CollationAgent->value)->firstOrFail();
        $ward = $units[0]->ward;
        $wardUnits = $units->where('ward_id', $ward->id)->keys();
        $sum = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        foreach ($wardUnits as $k) {
            $v = $k === 4 ? ['A' => 199, 'B' => 112, 'C' => 30, 'D' => 8] : ($figures[$k][1] ?? []);
            foreach ($v as $p => $n) {
                $sum[$p] += $n;
            }
        }
        $sum['B'] += 40;
        $this->send($collator, 'POST', 'collation-entries', ['level' => 'ward', 'scope_id' => $ward->id, 'votes' => $sum, 'announced_at' => $at('19:30')]);

        // IReV: unit 1 matches, unit 2 shows B higher, unit 9 is missing.
        $officer = User::where('campaign_id', $this->campaign->id)->where('role', Role::DataOfficer->value)->firstOrFail();
        $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[0]->id, 'not_on_irev' => false, 'votes' => $figures[0][1], 'seen_at' => $at('17:00')]);
        $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[1]->id, 'not_on_irev' => false, 'votes' => ['A' => 175, 'B' => 212, 'C' => 20, 'D' => 4], 'seen_at' => $at('17:04')]);
        $this->send($officer, 'POST', 'irev-entries', ['polling_unit_id' => $units[8]->id, 'not_on_irev' => true, 'seen_at' => $at('17:10')]);
    }

    private function submitResult(User $agent, PollingUnit $unit, array $votes, int $acc, int $rej, string $capturedAt, ?int $supersedes = null, ?string $reason = null): int
    {
        if (! isset($this->secrets[$agent->id])) {
            $pair = sodium_crypto_sign_keypair();
            $this->secrets[$agent->id] = sodium_crypto_sign_secretkey($pair);
            Device::create(['user_id' => $agent->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        }
        $secret = $this->secrets[$agent->id];
        $device = Device::where('user_id', $agent->id)->latest('id')->first();
        $lat = (float) $unit->latitude;
        $lng = (float) $unit->longitude;
        $canonical = ResultSigner::canonical($unit->code, $acc, $rej, $votes, null, $lat, $lng, $capturedAt, $supersedes);

        return $this->send($agent, 'POST', 'results', [
            'device_id' => $device->id,
            'signature' => base64_encode(sodium_crypto_sign_detached($canonical, $secret)),
            'captured_at' => $capturedAt,
            'accredited' => $acc,
            'rejected' => $rej,
            'votes' => $votes,
            'latitude' => $lat,
            'longitude' => $lng,
            'supersedes_id' => $supersedes,
            'correction_reason' => $reason,
        ])['id'];
    }

    /** Sends one request through the app as the given user, using a throwaway token. */
    private function send(User $user, string $method, string $path, array $body): array
    {
        $token = $user->createToken('demo')->plainTextToken;
        $request = Request::create("/api/v1/{$path}", $method, server: [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ], content: json_encode($body));

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
