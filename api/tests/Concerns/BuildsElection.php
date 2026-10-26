<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Device;
use App\Models\Lga;
use App\Models\Party;
use App\Models\PollingUnit;
use App\Models\User;
use App\Models\Ward;
use App\Services\ResultSigner;
use Laravel\Sanctum\Sanctum;

trait BuildsElection
{
    protected Campaign $campaign;

    /** @var array<string, Lga> */
    protected array $lgas = [];

    /** @var array<string, Ward> */
    protected array $wards = [];

    /** @var array<string, PollingUnit> */
    protected array $units = [];

    /**
     * Three LGAs, one ward each, two units per ward (PU1..PU6). Parties A (ours), B, C.
     */
    protected function buildElection(): void
    {
        $this->campaign = Campaign::create(['name' => 'Test', 'state_name' => 'Test State', 'election_date' => '2027-02-06']);
        foreach (['A', 'B', 'C'] as $i => $code) {
            Party::create(['campaign_id' => $this->campaign->id, 'code' => $code, 'name' => "Party {$code}", 'is_ours' => $code === 'A', 'sort_order' => $i]);
        }
        $n = 0;
        foreach (['L1', 'L2', 'L3'] as $i => $lgaCode) {
            $lga = $this->lgas[$lgaCode] = Lga::create(['campaign_id' => $this->campaign->id, 'code' => $lgaCode, 'name' => "LGA {$lgaCode}"]);
            $ward = $this->wards[$lgaCode] = Ward::create(['campaign_id' => $this->campaign->id, 'lga_id' => $lga->id, 'code' => 'W1', 'name' => "Ward of {$lgaCode}"]);
            foreach ([0, 1] as $j) {
                $n++;
                $this->units["PU{$n}"] = PollingUnit::create([
                    'campaign_id' => $this->campaign->id, 'ward_id' => $ward->id, 'code' => "PU{$n}", 'name' => "Unit {$n}",
                    'latitude' => 9.6 + $i * 0.1 + $j * 0.01, 'longitude' => 6.5,
                ]);
            }
        }
    }

    protected function staff(Role $role, array $attrs = []): User
    {
        return User::factory()->role($role)->create(['campaign_id' => $this->campaign->id] + $attrs);
    }

    protected function agentFor(string $unitCode, array $attrs = []): User
    {
        $unit = $this->units[$unitCode];

        return User::factory()->role(Role::Agent)->create([
            'campaign_id' => $this->campaign->id, 'polling_unit_id' => $unit->id, 'ward_id' => $unit->ward_id, 'code' => 'AG-'.$unitCode,
        ] + $attrs);
    }

    protected function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user, [$user->role->value]);

        return $user;
    }

    /** @return array{0: Device, 1: string} the device and its secret key */
    protected function deviceFor(User $agent): array
    {
        $pair = sodium_crypto_sign_keypair();
        $device = Device::create(['user_id' => $agent->id, 'public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

        return [$device, sodium_crypto_sign_secretkey($pair)];
    }

    /** Builds the request body the agent app sends, signed with the device key. */
    protected function signedResult(Device $device, string $secretKey, string $unitCode, array $votes, int $accredited, int $rejected = 0, ?int $supersedes = null, array $overrides = []): array
    {
        $capturedAt = '2027-02-06T15:41:00Z';
        $lat = 9.6;
        $lng = 6.5;
        $canonical = ResultSigner::canonical($unitCode, $accredited, $rejected, $votes, null, $lat, $lng, $capturedAt, $supersedes);

        return array_merge([
            'device_id' => $device->id,
            'signature' => base64_encode(sodium_crypto_sign_detached($canonical, $secretKey)),
            'captured_at' => $capturedAt,
            'accredited' => $accredited,
            'rejected' => $rejected,
            'votes' => $votes,
            'latitude' => $lat,
            'longitude' => $lng,
            'supersedes_id' => $supersedes,
            'correction_reason' => $supersedes ? 'A figure was typed wrongly' : null,
        ], $overrides);
    }

    /** Record a valid signed result for a unit directly through the API. */
    protected function submitResult(string $unitCode, array $votes, int $accredited, int $rejected = 0): int
    {
        $agent = User::where('polling_unit_id', $this->units[$unitCode]->id)->first() ?? $this->agentFor($unitCode);
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);

        return $this->postJson('/api/v1/results', $this->signedResult($device, $sk, $unitCode, $votes, $accredited, $rejected))
            ->assertCreated()->json('id');
    }
}
