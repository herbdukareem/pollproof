<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\Incident;
use App\Models\ResultSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class UssdTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    private User $agent;

    /** @var list<string> every reply seen, to check screen lengths */
    private array $screens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        config(['pollproof.ussd.token' => 'at-secret']);
        $this->agent = $this->agentFor('PU1', ['phone' => '08030000001']);
    }

    /** One Africa's Talking request: the answers so far, joined with "*". */
    private function dial(string $text = '', string $session = 'S1', string $phone = '+2348030000001'): TestResponse
    {
        $res = $this->post('/api/v1/ussd/africastalking/at-secret', [
            'sessionId' => $session, 'serviceCode' => '*384*1234#', 'phoneNumber' => $phone, 'networkCode' => '62130', 'text' => $text,
        ]);
        $this->screens[] = $res->getContent();

        return $res;
    }

    private function assertScreen(TestResponse $res, string $prefix, string $contains): void
    {
        $res->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $body = $res->getContent();
        $this->assertStringStartsWith($prefix.' ', $body);
        $this->assertStringContainsString($contains, $body);
    }

    protected function tearDown(): void
    {
        foreach ($this->screens as $screen) {
            $this->assertLessThanOrEqual(186, mb_strlen($screen), "Screen too long for USSD:\n{$screen}");
        }
        parent::tearDown();
    }

    public function test_the_callback_needs_the_secret_and_a_registered_agent(): void
    {
        $this->post('/api/v1/ussd/africastalking/wrong', ['sessionId' => 'x', 'phoneNumber' => '+2348030000001', 'text' => ''])->assertNotFound();
        $this->assertScreen($this->dial('', 'S9', '+2348099999999'), 'END', 'not registered');

        $this->agent->update(['deactivated_at' => now()]);
        $this->assertScreen($this->dial('', 'S8'), 'END', 'no longer active');
    }

    public function test_an_agent_sends_a_result_step_by_step_and_a_retry_does_not_duplicate_it(): void
    {
        $this->assertScreen($this->dial(''), 'CON', '1. Send result');
        $this->assertScreen($this->dial('1'), 'CON', 'Step 1/5: ACCREDITED voters for PU1');
        $this->assertScreen($this->dial('1*abc'), 'CON', 'numbers only');
        $this->assertScreen($this->dial('1*abc*412'), 'CON', 'Step 2/5: votes for A');
        $this->assertScreen($this->dial('1*abc*412*210'), 'CON', 'Step 3/5: votes for B');
        $this->assertScreen($this->dial('1*abc*412*210*150'), 'CON', 'Step 4/5: votes for C');
        $this->assertScreen($this->dial('1*abc*412*210*150*30'), 'CON', 'Step 5/5: REJECTED');
        $confirm = $this->dial('1*abc*412*210*150*30*7');
        $this->assertScreen($confirm, 'CON', 'A 210, B 150, C 30');
        $this->assertStringContainsString('Total cast 397', $confirm->getContent());

        $sent = $this->dial('1*abc*412*210*150*30*7*1');
        $this->assertScreen($sent, 'END', 'Result sent for PU1');
        $this->assertSame($sent->getContent(), $this->dial('1*abc*412*210*150*30*7*1')->getContent(), 'a retried request gets the same reply');

        $result = ResultSubmission::sole();
        $this->assertSame('ussd', $result->channel);
        $this->assertSame([412, 7, 397], [$result->accredited, $result->rejected, $result->total_cast]);
        $this->assertSame(ResultSubmission::PENDING_REVIEW, $result->status);
        $this->assertFalse($result->signature_valid);
        $this->assertStringContainsString($result->fingerprint(), $sent->getContent());
        $this->assertTrue(AuditLog::where('action', 'result.submitted_ussd')->exists());

        $this->assertScreen($this->dial('2', 'S2'), 'END', 'Status: Waiting for verification');
    }

    public function test_an_overvote_is_warned_about_but_still_recorded(): void
    {
        $this->assertScreen($this->dial('1*100*90*20*5*3'), 'CON', '! Total is more than accredited');
        $this->dial('1*100*90*20*5*3*1');
        $this->assertSame(['overvote'], ResultSubmission::sole()->flags);
        $this->assertTrue(Alert::where('type', 'overvote')->exists());
    }

    public function test_a_correction_supersedes_the_earlier_result(): void
    {
        $this->dial('1*412*210*150*30*7*1');
        $first = ResultSubmission::sole();

        $this->assertScreen($this->dial('', 'S2'), 'CON', '1. Correct my result');
        $this->assertScreen($this->dial('1', 'S2'), 'CON', 'already has a result: total 397');
        $this->assertScreen($this->dial('1*1', 'S2'), 'CON', 'Why correct it?');
        $this->assertScreen($this->dial('1*1*1', 'S2'), 'CON', 'Step 1/5');
        $this->assertScreen($this->dial('1*1*1*412*201*150*30*7', 'S2'), 'CON', '(correction)');
        $this->assertScreen($this->dial('1*1*1*412*201*150*30*7*1', 'S2'), 'END', 'Correction sent');

        $current = ResultSubmission::where('is_current', true)->sole();
        $this->assertSame($first->id, $current->supersedes_id);
        $this->assertSame('USSD: I typed a figure wrongly', $current->correction_reason);
        $this->assertSame(388, $current->total_cast);
    }

    public function test_a_dropped_session_carries_on_where_it_stopped(): void
    {
        $this->dial('', 'S1');
        $this->dial('1*412*210', 'S1'); // the network drops the session here

        $this->assertScreen($this->dial('', 'S2'), 'CON', '1. Continue result (2/5 done)');
        $this->assertScreen($this->dial('1', 'S2'), 'CON', 'Step 3/5: votes for B');
        $this->dial('1*150*30*7*1', 'S2');
        $this->assertSame(397, ResultSubmission::sole()->total_cast);

        $this->assertScreen($this->dial('', 'S3'), 'CON', '1. Correct my result');
    }

    public function test_nothing_is_kept_until_a_figure_is_typed_and_a_correction_resumes_as_one(): void
    {
        $this->dial('1*412*210*150*30*7*1');
        $this->dial('1*1*1', 'S2'); // opened a correction, typed nothing
        $this->assertScreen($this->dial('', 'S3'), 'CON', '1. Correct my result');
        $this->dial('1*1*1*412', 'S3'); // typed the first figure, then dropped
        $this->assertScreen($this->dial('', 'S4'), 'CON', '1. Continue correction (1/5 done)');
    }

    public function test_cancel_sends_nothing(): void
    {
        $this->assertScreen($this->dial('1*412*210*150*30*7*0'), 'END', 'Nothing was sent');
        $this->assertSame(0, ResultSubmission::count());
        $this->assertScreen($this->dial('', 'S2'), 'CON', '1. Send result');
    }

    public function test_check_in_by_ussd_waits_for_the_supervisor(): void
    {
        $this->assertScreen($this->dial('3'), 'CON', 'Confirm you are at PU1');
        $this->assertScreen($this->dial('3*1'), 'END', 'supervisor will confirm');
        $checkIn = CheckIn::sole();
        $this->assertSame(['ussd', CheckIn::PENDING_REVIEW], [$checkIn->channel, $checkIn->status]);
        $this->assertNull($checkIn->latitude);

        $this->assertScreen($this->dial('3', 'S2'), 'END', 'waiting for your supervisor');
    }

    public function test_an_urgent_incident_alerts_the_situation_room(): void
    {
        $this->assertScreen($this->dial('4'), 'CON', '1. Violence or disruption');
        $this->assertScreen($this->dial('4*1'), 'CON', 'How serious?');
        $this->assertScreen($this->dial('4*1*1'), 'CON', 'Describe it');
        $this->assertScreen($this->dial('4*1*1*ok'), 'CON', 'Type a few words');
        $this->assertScreen($this->dial('4*1*1*ok*Thugs took the ballot box'), 'END', 'situation room has been alerted');

        $incident = Incident::sole();
        $this->assertSame(['disruption', 'high'], [$incident->type, $incident->severity]);
        $this->assertStringStartsWith('Thugs took the ballot box', $incident->description);
        $this->assertTrue(Alert::where('type', 'incident')->exists());
    }

    public function test_reserves_and_a_declared_election_get_a_clear_message(): void
    {
        User::factory()->role(Role::Agent)->create(['campaign_id' => $this->campaign->id, 'phone' => '08030000002', 'is_reserve' => true]);
        $this->assertScreen($this->dial('', 'S5', '2348030000002'), 'END', 'reserve agent');

        $this->campaign->update(['declared_at' => now()]);
        $this->assertScreen($this->dial('', 'S6'), 'END', 'declared');
    }

    public function test_check_in_is_daily_and_a_rejected_one_can_be_redone(): void
    {
        $this->dial('3*1');
        $this->assertSame(1, Alert::where('type', 'checkin_review')->where('status', 'open')->count(), 'supervisors are told');

        CheckIn::sole()->update(['status' => CheckIn::REJECTED]);
        $this->assertScreen($this->dial('3', 'S2'), 'CON', 'Confirm you are at PU1');
        $this->dial('3*1', 'S2');
        $this->assertSame(2, CheckIn::count());

        CheckIn::latest('id')->first()->update(['status' => CheckIn::APPROVED]);
        $this->assertScreen($this->dial('3', 'S3'), 'END', 'already checked in at PU1 today');

        $this->travel(1)->days();
        $this->assertScreen($this->dial('3', 'S4'), 'CON', 'Confirm you are at PU1');
    }

    public function test_a_server_error_says_so_instead_of_a_network_fault(): void
    {
        CheckIn::creating(fn () => throw new \RuntimeException('database refused the row'));
        $this->assertScreen($this->dial('3*1', 'S7'), 'END', 'PollProof could not finish this (ref ');
        CheckIn::flushEventListeners();
    }
}
