<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\Election;
use App\Models\Party;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class RacesTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
    }

    /** A State Assembly seat over LGA L1 (units PU1 and PU2), with parties A and B on its ballot. */
    private function assembly(array $overrides = []): Election
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $parties = Party::where('campaign_id', $this->campaign->id)->whereIn('code', ['A', 'B'])->orderBy('code')->get();
        $id = $this->postJson('/api/v1/elections', $overrides + [
            'type' => 'assembly', 'name' => 'Assembly (L1)', 'code' => 'hoa-1', 'election_date' => '2027-02-06',
            'lga_ids' => [$this->lgas['L1']->id],
            'parties' => [['id' => $parties[0]->id, 'candidate' => 'Hon. Aisha Bello'], ['id' => $parties[1]->id]],
        ])->assertCreated()->assertJsonPath('code', 'HOA-1')->assertJsonPath('area', 'LGA L1')->json('id');

        return Election::findOrFail($id);
    }

    public function test_a_campaign_starts_with_the_governorship_and_new_parties_join_every_open_ballot(): void
    {
        $race = $this->race();
        $this->assertSame([Election::GOVERNORSHIP, 'GOV', 'Whole state'], [$race->type, $race->code, $race->areaSummary()]);
        $this->assertSame(['A', 'B', 'C'], $race->parties()->pluck('code')->all());

        $assembly = $this->assembly();
        Party::create(['campaign_id' => $this->campaign->id, 'code' => 'D', 'name' => 'Party D', 'sort_order' => 3]);
        $this->assertContains('D', $race->parties()->pluck('code')->all());
        $this->assertSame(['A', 'B', 'D'], $assembly->parties()->pluck('code')->all());
    }

    public function test_the_dg_sets_up_a_race_and_its_area_and_ballot_are_checked(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $a = Party::where('campaign_id', $this->campaign->id)->where('code', 'A')->value('id');
        $b = Party::where('campaign_id', $this->campaign->id)->where('code', 'B')->value('id');
        $base = ['type' => 'senate', 'name' => 'Senate (South)', 'code' => 'SEN-S', 'election_date' => '2027-02-20', 'parties' => [['id' => $a], ['id' => $b]]];

        $this->postJson('/api/v1/elections', $base)->assertUnprocessable()->assertJsonPath('errors.area.0', 'Choose the LGAs in this race.');
        $this->postJson('/api/v1/elections', ['parties' => [['id' => $b], ['id' => Party::where('code', 'C')->where('campaign_id', $this->campaign->id)->value('id')]], 'lga_ids' => [$this->lgas['L1']->id]] + $base)
            ->assertUnprocessable()->assertJsonPath('errors.parties.0', 'Our party must be on the ballot.');
        $this->postJson('/api/v1/elections', ['code' => 'GOV', 'lga_ids' => [$this->lgas['L1']->id]] + $base)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/elections', ['type' => 'councillor', 'ward_ids' => [$this->wards['L1']->id, $this->wards['L2']->id]] + $base)
            ->assertUnprocessable()->assertJsonPath('errors.area.0', 'Choose the one ward this race is for.');

        $this->postJson('/api/v1/elections', ['lga_ids' => [$this->lgas['L1']->id, $this->lgas['L2']->id]] + $base)
            ->assertCreated()->assertJsonPath('area', 'LGA L1 and LGA L2')->assertJsonPath('final_level', 'Senatorial district');
        $this->actingAsUser($this->staff(Role::Verifier));
        $this->postJson('/api/v1/elections', $base)->assertForbidden();
        $this->getJson('/api/v1/elections')->assertOk()->assertJsonCount(2, 'races');
    }

    public function test_an_agent_sends_one_signed_result_per_race_at_their_unit(): void
    {
        $assembly = $this->assembly();
        $agent = $this->agentFor('PU1');
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);

        $me = $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['GOV', 'HOA-1'], array_column($me->json('races'), 'code'));
        $this->assertSame('Hon. Aisha Bello', $me->json('races.1.parties.0.candidate'));

        // A party not on the assembly ballot, and a signature that doesn't cover the race named.
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 1, 'C' => 1], 400, election: 'HOA-1'))
            ->assertUnprocessable()->assertJsonPath('errors.votes.0', 'Not on the Assembly (L1) ballot: C');
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 1, 'B' => 1], 400, 0, null, ['election' => 'HOA-1']))
            ->assertUnprocessable()->assertJsonValidationErrors('signature');

        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 200, 'B' => 150, 'C' => 30], 400, election: 'GOV'))->assertCreated();
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 220, 'B' => 140], 400, election: 'HOA-1'))->assertCreated();
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 221, 'B' => 140], 400, election: 'HOA-1'))
            ->assertUnprocessable()->assertJsonValidationErrors(['supersedes_id' => 'This unit already has a result for Assembly (L1)']);

        $this->assertSame(2, ResultSubmission::where('polling_unit_id', $this->units['PU1']->id)->where('is_current', true)->count());
        $this->assertSame(['GOV', 'HOA-1'], collect($this->getJson('/api/v1/me')->json('today.results'))->pluck('election')->sort()->values()->all());

        // An agent outside the seat can't send for it.
        $outside = $this->agentFor('PU3');
        [$d3, $sk3] = $this->deviceFor($outside);
        $this->actingAsUser($outside);
        $this->assertSame(['GOV'], array_column($this->getJson('/api/v1/me')->json('races'), 'code'));
        $this->postJson('/api/v1/results', $this->signedResult($d3, $sk3, 'PU3', ['A' => 1, 'B' => 1], 4, election: 'HOA-1'))
            ->assertUnprocessable()->assertJsonPath('message', 'PU3 is not in the Assembly (L1) race.');
    }

    public function test_an_app_from_before_races_still_sends_to_the_units_first_race(): void
    {
        $this->assembly();
        $id = $this->submitResult('PU1', ['A' => 200, 'B' => 150, 'C' => 30], 400);
        $this->assertSame('GOV', ResultSubmission::find($id)->election->code);
    }

    public function test_each_race_has_its_own_tally_board_verify_queue_and_units(): void
    {
        $assembly = $this->assembly();
        $this->submitResult('PU1', ['A' => 100, 'B' => 150, 'C' => 30], 300);
        $agent = User::where('polling_unit_id', $this->units['PU1']->id)->firstOrFail();
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 210, 'B' => 90], 320, election: 'HOA-1'))->assertCreated();

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson("/api/v1/tally?election={$assembly->id}")->assertOk()
            ->assertJsonPath('rule', 'plurality')->assertJsonPath('area_level', 'ward')->assertJsonPath('lga.id', $this->lgas['L1']->id)->assertJsonPath('units', 2)
            ->assertJsonPath('candidates.0.party', 'A')->assertJsonPath('candidates.0.votes', 210)->assertJsonPath('candidates.0.leads', true);
        $this->getJson('/api/v1/tally')->assertOk()->assertJsonPath('rule', 'spread')->assertJsonPath('candidates.0.party', 'B');

        $board = collect($this->getJson('/api/v1/tally/board')->assertOk()->json('races'))->keyBy('code');
        $this->assertSame(['trailing', 'leading'], [$board['GOV']['standing'], $board['HOA-1']['standing']]);
        $this->assertSame([1, 120], [$board['HOA-1']['units_reported'], $board['HOA-1']['margin']]);

        $this->assertSame(2, $this->getJson('/api/v1/results-queue')->json('counts.all'));
        $this->assertSame(1, $this->getJson("/api/v1/results-queue?election={$assembly->id}")->json('counts.all'));
        $units = $this->getJson("/api/v1/units?election={$assembly->id}")->assertOk();
        $this->assertSame(['all' => 2, 'reported' => 1], array_intersect_key($units->json('counts'), ['all' => 1, 'reported' => 1]));
        $this->assertSame(['A' => 210, 'B' => 90], collect($units->json('data'))->firstWhere('code', 'PU1')['result']['votes']);
    }

    public function test_collation_and_irev_are_checked_within_the_race(): void
    {
        $assembly = $this->assembly();
        $agent = $this->agentFor('PU1');
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 210, 'B' => 90], 320, election: 'HOA-1'))->assertCreated();

        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson("/api/v1/collation-entries?election={$assembly->id}", ['level' => 'lga', 'scope_id' => $this->lgas['L2']->id, 'votes' => ['A' => 1], 'announced_at' => now()->toIso8601String()])
            ->assertUnprocessable();
        $res = $this->postJson("/api/v1/collation-entries?election={$assembly->id}", ['level' => 'state', 'votes' => ['A' => 200, 'B' => 95], 'announced_at' => now()->toIso8601String()])
            ->assertCreated();
        $this->assertSame(['A' => -10, 'B' => 5], $res->json('comparison.differences'));
        $this->assertSame('Assembly (L1)', $res->json('comparison.scope'));

        $irev = $this->postJson("/api/v1/irev-entries?election={$assembly->id}", ['polling_unit_id' => $this->units['PU1']->id, 'not_on_irev' => false, 'votes' => ['A' => 210, 'B' => 90], 'seen_at' => now()->toIso8601String()])
            ->assertCreated();
        $this->assertSame('match', $irev->json('comparison.status'));
        // The governorship has no result at PU1, so the same figures there have nothing to match.
        $this->assertSame('no_agent_result', $this->postJson('/api/v1/irev-entries', ['polling_unit_id' => $this->units['PU1']->id, 'not_on_irev' => false, 'votes' => ['A' => 210], 'seen_at' => now()->toIso8601String()])->json('comparison.status'));
        $alert = Alert::where('type', 'collation_mismatch')->firstOrFail();
        $this->assertSame([$assembly->id, 'Assembly (L1): declared figures differ from our units'], [$alert->election_id, $alert->title]);
    }

    public function test_alerts_filter_by_race_and_keep_those_about_no_race(): void
    {
        $assembly = $this->assembly();
        $alert = fn (string $key, ?int $race) => Alert::create(['campaign_id' => $this->campaign->id, 'election_id' => $race, 'type' => 'overvote', 'severity' => 'high', 'dedupe_key' => $key, 'title' => $key, 'detail' => 'x', 'status' => 'open', 'opened_at' => now()]);
        $alert('governorship', $this->race()->id);
        $alert('assembly', $assembly->id);
        $alert('incident', null);
        $this->actingAsUser($this->staff(Role::SituationRoom));

        $titles = fn (string $q) => collect($this->getJson("/api/v1/alerts{$q}")->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['assembly', 'governorship', 'incident'], $titles(''));
        $this->assertSame(['assembly', 'incident'], $titles("?election={$assembly->id}"));
    }

    public function test_declaring_one_race_locks_only_that_race(): void
    {
        $assembly = $this->assembly();
        $this->actingAsUser($this->staff(Role::Dg));
        $this->postJson("/api/v1/closeout/declare?election={$assembly->id}", ['declared_at' => now()->subHour()->toIso8601String(), 'votes' => ['A' => 500, 'B' => 400]])
            ->assertOk()->assertJsonPath('declared', true)->assertJsonPath('all_declared', false);

        $agent = $this->agentFor('PU1');
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 1, 'B' => 1], 4, election: 'HOA-1'))->assertStatus(423);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 1, 'B' => 1, 'C' => 1], 4, election: 'GOV'))->assertCreated();
        $this->assertFalse($this->campaign->fresh()->isDeclared());
    }

    public function test_sms_and_ussd_ask_which_race_when_the_unit_has_more_than_one(): void
    {
        $this->assembly();
        $agent = $this->agentFor('PU1', ['phone' => '08030000001']);
        config(['pollproof.sms_webhook_token' => 'tok']);
        $sms = fn (string $text) => $this->postJson('/api/v1/sms/inbound', ['from' => '08030000001', 'text' => $text], ['X-Webhook-Token' => 'tok'])->json('reply');

        $this->assertStringStartsWith('Your unit has 2 races. Start with the race code: R GOV PU1 ..., R HOA-1 PU1 ...', $sms('R PU1 AC300 A100 B90 X2'));
        $this->assertStringStartsWith('OK HOA-1 PU1 total 192', $sms('R HOA-1 PU1 AC300 A100 B90 X2'));
        $this->assertSame('HOA-1', ResultSubmission::where('channel', 'sms')->firstOrFail()->election->code);

        config(['pollproof.ussd.token' => 'ussd']);
        $ussd = fn (string $text) => $this->post('/api/v1/ussd/africastalking/ussd', ['sessionId' => 's1', 'phoneNumber' => '+2348030000001', 'text' => $text])->getContent();
        $this->assertStringContainsString('1. Send a result', $ussd(''));
        $this->assertStringContainsString("Which result sheet?\n1. Governorship\n2. Assembly (L1) (sent)", $ussd('1'));
        $this->assertStringContainsString('GOV PU1', $ussd('1*1'));
        $this->assertStringContainsString("GOV: not sent\nHOA-1: 192 cast, waiting for verification", $ussd('2'));
    }
}
