<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Queries that depend on the database engine (MySQL): search, JSON flags and the audit chain lock. */
class DatabaseQueriesTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    public function test_unit_search_ignores_case_and_matches_names(): void
    {
        $this->units['PU3']->update(['name' => 'Central Primary School']);
        $this->actingAsUser($this->staff(Role::SituationRoom));

        $this->getJson('/api/v1/units?search=pu3')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PU3');
        $this->getJson('/api/v1/units?search=primary')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_over_votes_are_found_in_the_json_flags_and_verified_first(): void
    {
        $this->submitResult('PU1', ['A' => 10, 'B' => 5], 20);
        $this->submitResult('PU2', ['A' => 30, 'B' => 25], 40); // 55 cast against 40 accredited

        $this->actingAsUser($this->staff(Role::Verifier));
        $this->getJson('/api/v1/units?status=flagged')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'PU2');
        $this->getJson('/api/v1/results-queue')->assertOk()->assertJsonPath('data.0.polling_unit.code', 'PU2');
    }

    public function test_the_audit_chain_holds_with_entries_with_and_without_a_campaign(): void
    {
        $audit = app(AuditLogger::class);
        $user = $this->staff(Role::Dg);
        $audit->log(null, 'auth.failed_login', null, ['email' => 'nobody@example.com']);
        foreach (range(1, 5) as $i) {
            $audit->log($user, 'test.entry', null, ['n' => $i, 'z' => ['b' => 2, 'a' => 1]]);
        }

        $this->assertTrue($audit->verify($this->campaign->id)['valid']);
        $this->assertSame(1, AuditLog::whereNull('campaign_id')->count());
    }
}
