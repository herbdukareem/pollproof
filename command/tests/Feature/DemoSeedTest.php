<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ResultSubmission;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ElectionDaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_election_day_plays_through_the_api(): void
    {
        $this->seed([DatabaseSeeder::class, ElectionDaySeeder::class]);

        $this->assertSame(9, ResultSubmission::count());
        $this->assertSame(8, ResultSubmission::where('is_current', true)->count());
        $this->assertTrue(ResultSubmission::where('channel', 'app')->get()->every->signature_valid);
        $this->assertEqualsCanonicalizing(
            ['incident', 'collation_mismatch', 'overvote', 'irev_mismatch', 'irev_missing'],
            Alert::where('status', 'open')->pluck('type')->unique()->values()->all(),
        );
        $this->artisan('pollproof:verify-audit', ['campaign' => 1])->assertSuccessful();
    }
}
