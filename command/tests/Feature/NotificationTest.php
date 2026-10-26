<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\Notice;
use App\Models\NotificationRule;
use App\Services\ElectionWatch;
use App\Services\Sms\LogSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        LogSmsSender::$sent = [];
    }

    public function test_new_campaigns_get_the_default_rules(): void
    {
        $this->assertSame(count(NotificationRule::DEFAULTS), $this->campaign->notificationRules()->count());
        $this->assertSame(['legal', 'verifier'], $this->campaign->notificationRules()->where('event', 'overvote')->first()->recipients);
    }

    public function test_an_alert_reaches_the_rule_roles_and_only_the_supervisors_of_that_place(): void
    {
        $legal = $this->staff(Role::Legal);
        $lgaSup = $this->staff(Role::LgaSupervisor, ['lga_id' => $this->lgas['L1']->id]);
        $otherSup = $this->staff(Role::LgaSupervisor, ['lga_id' => $this->lgas['L2']->id]);
        $finance = $this->staff(Role::Finance);
        $collator = $this->staff(Role::CollationAgent, ['ward_id' => $this->wards['L1']->id, 'lga_id' => $this->lgas['L1']->id]);

        $this->submitResult('PU1', ['A' => 100, 'B' => 50], 160);
        $this->actingAsUser($this->staff(Role::DataOfficer));
        $this->postJson('/api/v1/collation-entries', ['level' => 'ward', 'scope_id' => $this->wards['L1']->id, 'votes' => ['A' => 100, 'B' => 90], 'announced_at' => now()->toIso8601String()])->assertCreated();

        $told = Notice::whereHas('alert', fn ($q) => $q->where('type', 'collation_mismatch'))->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$legal->id, $lgaSup->id, $collator->id])->sort()->values()->all(), $told);
        $this->assertNotContains($otherSup->id, $told);
        $this->assertNotContains($finance->id, $told);
        $this->assertTrue(collect(LogSmsSender::$sent)->contains(fn ($m) => $m['phone'] === $lgaSup->phone && str_contains($m['message'], 'declared figures differ')));

        // A second identical check refreshes the open alert without telling everyone again.
        $this->postJson('/api/v1/collation-entries', ['level' => 'ward', 'scope_id' => $this->wards['L1']->id, 'votes' => ['A' => 100, 'B' => 90], 'announced_at' => now()->toIso8601String()]);
        $this->assertSame(3, Notice::whereHas('alert', fn ($q) => $q->where('type', 'collation_mismatch'))->count());
    }

    public function test_an_alert_nobody_acts_on_is_escalated_once(): void
    {
        $dg = $this->staff(Role::Dg);
        $alert = app(\App\Services\AlertService::class)->raise($this->campaign->id, 'overvote', 'high', 'overvote:test', 'PU1: votes exceed accreditation', 'Detail', $this->units['PU1']);
        $watch = app(ElectionWatch::class);

        $this->assertSame(0, $watch->escalate($this->campaign, now()->addMinutes(4)));
        $this->assertSame(1, $watch->escalate($this->campaign, now()->addMinutes(6)));
        $this->assertSame(0, $watch->escalate($this->campaign, now()->addMinutes(20)), 'only once');
        $this->assertSame('No action after 5 min: PU1: votes exceed accreditation', Notice::where('user_id', $dg->id)->value('title'));

        // Acknowledged alerts are not escalated.
        $other = app(\App\Services\AlertService::class)->raise($this->campaign->id, 'overvote', 'high', 'overvote:two', 'PU2', 'Detail', $this->units['PU2']);
        $other->update(['status' => 'acknowledged']);
        $this->assertSame(0, $watch->escalate($this->campaign, now()->addMinutes(30)));
    }

    public function test_units_without_a_check_in_by_the_deadline_raise_alerts_on_election_day_only(): void
    {
        $this->campaign->update(['election_date' => '2027-02-06', 'checkin_deadline' => '08:30']);
        $ws = $this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id, 'lga_id' => $this->lgas['L1']->id]);
        $agent = $this->agentFor('PU1');
        $watch = app(ElectionWatch::class);

        $this->assertSame(0, $watch->absentAgents($this->campaign->fresh(), Carbon::parse('2027-02-06 08:29', 'Africa/Lagos')));
        $this->assertSame(0, $watch->absentAgents($this->campaign->fresh(), Carbon::parse('2027-02-05 09:00', 'Africa/Lagos')));
        $this->assertSame(6, $watch->absentAgents($this->campaign->fresh(), Carbon::parse('2027-02-06 08:31', 'Africa/Lagos')));
        $this->assertSame('PU1: no agent checked in by 8:30 am', Alert::where('dedupe_key', "agent_absent:{$this->units['PU1']->id}")->value('title'));
        $this->assertSame(2, Notice::where('user_id', $ws->id)->count(), 'only the two units in their ward');

        // The agent arrives and checks in: the alert clears.
        $this->actingAsUser($agent);
        $u = $this->units['PU1'];
        $this->postJson('/api/v1/check-ins', ['latitude' => $u->latitude, 'longitude' => $u->longitude, 'accuracy_m' => 5, 'captured_at' => now()->toIso8601String()])->assertCreated();
        $this->assertSame('resolved', Alert::where('dedupe_key', "agent_absent:{$u->id}")->value('status'));
    }

    public function test_people_read_their_notices_and_the_dg_edits_rules(): void
    {
        $dg = $this->actingAsUser($this->staff(Role::Dg));
        app(\App\Services\AlertService::class)->raise($this->campaign->id, 'failed_logins', 'high', 'failed_logins:x', 'Three failed sign-ins', 'Detail', $dg);
        $this->getJson('/api/v1/me/notices')->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('data.0.url', '/admin/users');
        $this->postJson('/api/v1/me/notices/read')->assertJsonPath('unread', 0);

        $rule = NotificationRule::where('event', 'irev_missing')->first();
        $this->patchJson("/api/v1/notification-rules/{$rule->id}", ['recipients' => ['data_officer', 'legal'], 'channels' => ['app', 'sms'], 'escalate_after_minutes' => 30])
            ->assertOk()->assertJsonPath('data.6.recipients', ['data_officer', 'legal']);
        $this->patchJson("/api/v1/notification-rules/{$rule->id}", ['channels' => ['whatsapp']])->assertJsonValidationErrors('channels.0');

        $this->postJson('/api/v1/notification-rules/test')->assertOk()->assertJson(['sent' => count(NotificationRule::DEFAULTS)]);
        $this->assertSame(count(NotificationRule::DEFAULTS), Notice::where('user_id', $dg->id)->where('test', true)->count());
    }
}
