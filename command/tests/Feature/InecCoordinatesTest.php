<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\PollingUnit;
use App\Services\InecLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Pulling polling units and INEC's coordinates from the public locator, against a faked portal. */
class InecCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    private int $flaky = 0;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(storage_path('app/inec'));
        // The portal's real answers: [{id: "code - NAME", ..., selected: ...}], and a 302 to Google Maps.
        Http::fake(function (Request $r) {
            $q = $r->data();
            $url = $r->url();
            if (str_contains($url, 'PublicApi/lgas')) {
                return Http::response([['540' => '03 - BIDA', '537' => '01 - AGAIE', 'selected' => '']]);
            }
            if (str_contains($url, 'PublicApi/wards')) {
                return Http::response(str_contains($url, '=537') ? [['16989' => '01 - BARO']] : [['17010' => '01 - BARIKI', '17011' => '02 - CENTENARY CITY']]);
            }
            if (str_contains($url, 'PublicApi/pus')) {
                return Http::response(match (true) {
                    str_contains($url, '=16989') => [['86521' => '001 - AKWANO', '86522' => '002 - BARO I']],
                    str_contains($url, '=17010') => [['90001' => '001 - GOVERNMENT MODEL SCHOOL']],
                    default => [['90100' => '001 - CENTENARY PRY SCH']],
                });
            }
            if (str_contains($url, 'pu_locator/index')) {
                $pu = $q['data[Search][polling_unit_id]'] ?? '';
                if ($pu === '90100' && $this->flaky++ < 1) {
                    return Http::response('busy', 503); // first try fails, the retry works
                }

                return match ($pu) {
                    '86521' => Http::response('', 302, ['Location' => 'https://maps.google.com/?q=8.589371712,6.42511812']),
                    '86522' => Http::response('', 302, ['Location' => 'https://www.google.com/maps?q=0,0']), // INEC has no location
                    '90001' => Http::response('', 302, ['Location' => 'https://maps.google.com/?q=9.0817,6.0093']),
                    default => Http::response('', 302, ['Location' => 'https://maps.google.com/?q=9.0761,6.0154']),
                };
            }

            return Http::response('not found', 404);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/inec'));
        parent::tearDown();
    }

    public function test_it_fetches_units_and_coordinates_and_loads_them_into_a_campaign(): void
    {
        $campaign = Campaign::create(['name' => 'Niger', 'state_name' => 'Niger', 'election_date' => '2027-02-06']);
        // A pin a supervisor dropped earlier on a unit INEC has no location for: it is kept.
        $this->artisan('pollproof:inec-coordinates', ['state' => 'niger', '--concurrency' => 2, '--campaign' => $campaign->id])
            ->expectsOutputToContain('2 LGAs, 3 wards, 4 polling units')
            ->assertSuccessful();

        $csv = array_map('str_getcsv', file(storage_path('app/inec/niger-polling-units.csv'), FILE_IGNORE_NEW_LINES));
        $this->assertSame(['lga_code', 'lga_name', 'ward_code', 'ward_name', 'pu_code', 'pu_name', 'latitude', 'longitude', 'registered_voters'], $csv[0]);
        $this->assertSame(['01', 'Agaie', '01', 'Baro', '26-01-01-001', 'AKWANO', '8.589371712', '6.42511812', ''], $csv[1]);
        $this->assertSame('', $csv[2][6], 'no location from INEC stays blank');

        $this->assertSame(4, PollingUnit::where('campaign_id', $campaign->id)->count());
        $unit = PollingUnit::where('code', '26-03-02-001')->sole();
        $this->assertEqualsWithDelta(9.0761, $unit->latitude, 0.00001, 'fetched after a retry');

        // A pin dropped on the unit INEC had no location for survives the next run.
        PollingUnit::where('code', '26-01-01-002')->update(['latitude' => 8.6, 'longitude' => 6.43]);
        $this->artisan('pollproof:inec-coordinates', ['state' => 'niger', '--campaign' => $campaign->id])
            ->expectsOutputToContain('Using the saved list')->assertSuccessful();
        $this->assertEqualsWithDelta(8.6, PollingUnit::where('code', '26-01-01-002')->value('latitude'), 0.0001);
    }

    public function test_frozen_campaigns_and_unknown_states_are_refused(): void
    {
        $campaign = Campaign::create(['name' => 'N', 'state_name' => 'Niger', 'election_date' => '2027-02-06', 'freeze_at' => now()->subDay()]);
        $this->artisan('pollproof:inec-coordinates', ['state' => 'niger', '--campaign' => $campaign->id])->expectsOutputToContain('locked')->assertFailed();
        $this->artisan('pollproof:inec-coordinates', ['state' => 'atlantis'])->assertFailed();
    }

    public function test_codes_and_redirects(): void
    {
        $this->assertSame([27, '26'], [InecLocator::stateId('Niger'), InecLocator::delimitationCode(27)]);
        $this->assertSame('37', InecLocator::delimitationCode(15), 'the FCT is last in INEC codes');
        $this->assertSame('25', InecLocator::delimitationCode(26), 'Nasarawa');
        $this->assertSame(['lat' => 9.5, 'lng' => 6.5], InecLocator::parseLocation('https://www.google.com/maps/dir/?api=1&destination=9.5%2C6.5'));
        $this->assertNull(InecLocator::parseLocation('https://maps.google.com/?q=51.5,-0.1'), 'outside Nigeria');
    }
}
