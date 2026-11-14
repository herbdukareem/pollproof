<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Alert;
use App\Models\Announcement;
use App\Models\Campaign;
use App\Models\NotificationRule;
use App\Services\AlertService;
use App\Services\ElectionWatch;
use App\Services\Voice\RaceWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class VoiceTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    private bool $kokoroUp = true;

    private const WAV = "RIFF\x24\0\0\0WAVEfmt \x10\0\0\0\x01\0\x01\0\x40\x1f\0\0\x80\x3e\0\0\x02\0\x10\0data\0\0\0\0";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        Storage::fake('local');
        Http::fake(fn () => $this->kokoroUp ? Http::response(self::WAV, 200, ['Content-Type' => 'audio/wav']) : Http::response('busy', 500));
        config(['pollproof.voice.enabled' => true, 'pollproof.voice.socket' => '', 'pollproof.voice.url' => 'http://kokoro.test/tts']);
    }

    private function reportIncident(string $unit, string $type = 'disruption', string $severity = 'high'): void
    {
        $this->actingAsUser($this->agentFor($unit));
        $this->postJson('/api/v1/incidents', [
            'type' => $type, 'severity' => $severity, 'description' => 'Thugs scattered the queue',
            'occurred_at' => now()->toIso8601String(), 'client_ref' => (string) Str::uuid(),
        ])->assertCreated();
    }

    public function test_nothing_is_announced_while_the_voice_is_off(): void
    {
        config(['pollproof.voice.enabled' => false]);
        $this->reportIncident('PU1');
        $this->assertDatabaseCount('announcements', 0);

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson('/api/v1/me')->assertJsonPath('rules.voice', false);
        $this->getJson('/api/v1/voice/feed')->assertOk()->assertJsonPath('enabled', false);
        Http::assertNothingSent();
    }

    public function test_an_incident_is_said_once_by_name_and_its_audio_is_made_once(): void
    {
        $room = $this->staff(Role::SituationRoom);
        $this->actingAsUser($room);
        $cursor = $this->getJson('/api/v1/voice/feed')->assertJsonPath('items', [])->json('cursor');

        $this->reportIncident('PU1');
        // The high incident also raises an alert; that alert isn't announced a second time.
        $this->assertSame(['High priority incident. Disruption at Unit 1, in LGA L1.'], Announcement::pluck('text')->all());
        // Made ready as soon as it was announced.
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['text'] === 'High priority incident. Disruption at Unit 1, in LGA L1.' && $r['voice'] === 'af_heart');

        $this->actingAsUser($room);
        // Held until the 5-second window closes, in case more arrive with it.
        $this->getJson("/api/v1/voice/feed?after={$cursor}")->assertJsonPath('items', [])->assertJsonPath('cursor', $cursor);
        $this->travel(6)->seconds();
        $res = $this->getJson("/api/v1/voice/feed?after={$cursor}")->assertOk();
        $this->assertCount(1, $res->json('items'));
        $this->assertSame('/incidents', $res->json('items.0.link'));

        $audio = $res->json('items.0.audio');
        $this->get($audio)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->get($audio)->assertOk();
        Http::assertSentCount(1);

        // Nothing new: nothing to say.
        $this->getJson('/api/v1/voice/feed?after='.$res->json('cursor'))->assertJsonPath('items', []);
        // The link only plays sentences the server wrote.
        $this->get('/api/v1/voice/audio?t='.urlencode('Anything at all'))->assertForbidden();
    }

    public function test_an_open_alert_is_not_announced_again_but_an_escalation_is(): void
    {
        $alerts = app(AlertService::class);
        $alert = $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:1', 'PU2: result not on IReV', 'Not uploaded', $this->units['PU2']);
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:1', 'PU2: result not on IReV', 'Still not uploaded', $this->units['PU2']);
        $this->assertSame(['The result for Unit 2, in LGA L1 is not on IReV.'], Announcement::pluck('text')->all());

        $alert->update(['escalated_at' => null, 'opened_at' => now()->subHour()]);
        NotificationRule::where('campaign_id', $this->campaign->id)->where('event', 'irev_missing')
            ->update(['enabled' => true, 'escalate_after_minutes' => 10, 'escalate_to' => json_encode(['dg'])]);
        app(ElectionWatch::class)->escalate(Campaign::find($this->campaign->id), now());

        $this->assertSame('Still not handled. The result for Unit 2, in LGA L1 is not on IReV.', Announcement::latest('id')->value('text'));
        $this->assertSame('escalation', Announcement::latest('id')->value('kind'));
    }

    public function test_the_same_sentence_is_not_repeated_within_twenty_minutes(): void
    {
        $alerts = app(AlertService::class);
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:a', 'PU2: result not on IReV', 'x', $this->units['PU2']);
        Alert::query()->update(['status' => 'resolved']);
        $this->travel(5)->minutes();
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:a', 'PU2: result not on IReV', 'y', $this->units['PU2'], ['again' => 1]);
        $this->assertSame(1, Announcement::count());

        Alert::query()->update(['status' => 'resolved']);
        $this->travel(21)->minutes();
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:a', 'PU2: result not on IReV', 'z', $this->units['PU2'], ['again' => 2]);
        $this->assertSame(2, Announcement::count());
    }

    public function test_announcements_arriving_together_are_said_as_one_summary(): void
    {
        $room = $this->staff(Role::SituationRoom);
        $this->actingAsUser($room);
        $cursor = $this->getJson('/api/v1/voice/feed')->json('cursor');

        $alerts = app(AlertService::class);
        // Within five seconds: an overvote and five units without an agent, in two LGAs.
        $alerts->raise($this->campaign->id, 'overvote', 'high', 'ov:1', 'PU3: votes cast exceed accreditation', 'x', $this->units['PU3']);
        foreach (['PU1', 'PU2', 'PU4', 'PU5', 'PU6'] as $i => $code) {
            $i % 2 && $this->travel(1)->seconds();
            $alerts->raise($this->campaign->id, 'agent_absent', 'medium', "absent:{$code}", "{$code}: no agent", 'x', $this->units[$code]);
        }
        // Five seconds after the first: the next window.
        $this->travel(3)->seconds();
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:9', 'PU6: result not on IReV', 'x', $this->units['PU6']);

        $res = $this->getJson("/api/v1/voice/feed?after={$cursor}")->assertOk();
        $this->assertSame([
            'Six new alerts in the last few seconds.',
            'Overvote at Unit 3, in LGA L2. Votes cast exceed accredited voters.',
            'Also: 5 units with no agent checked in, across 3 LGAs. See the alerts page.',
        ], array_column($res->json('items'), 'text'));
        $this->assertSame('high', $res->json('items.0.severity'));
        $this->assertCount(1, array_unique(array_column($res->json('items'), 'group')));

        // The later alert is in a window still open; it comes on the next poll, on its own.
        $this->travel(6)->seconds();
        $this->assertSame(['The result for Unit 6, in LGA L3 is not on IReV.'],
            array_column($this->getJson('/api/v1/voice/feed?after='.$res->json('cursor'))->json('items'), 'text'));
    }

    public function test_two_or_three_together_are_each_read_after_a_count(): void
    {
        $this->actingAsUser($this->staff(Role::SituationRoom));
        $cursor = $this->getJson('/api/v1/voice/feed')->json('cursor');
        $alerts = app(AlertService::class);
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:1', 't', 'x', $this->units['PU1']);
        $alerts->raise($this->campaign->id, 'overvote', 'high', 'ov:2', 't', 'x', $this->units['PU2']);
        $this->travel(6)->seconds();

        $this->assertSame([
            'Two new alerts.',
            'Overvote at Unit 2, in LGA L1. Votes cast exceed accredited voters.',
            'The result for Unit 1, in LGA L1 is not on IReV.',
        ], array_column($this->getJson("/api/v1/voice/feed?after={$cursor}&kinds=alert,race")->json('items'), 'text'));
    }

    public function test_a_supervisor_hears_only_their_own_lga(): void
    {
        $sup = $this->staff(Role::LgaSupervisor, ['lga_id' => $this->lgas['L2']->id]);
        $this->actingAsUser($sup);
        $cursor = $this->getJson('/api/v1/voice/feed')->json('cursor');
        $alerts = app(AlertService::class);
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:1', 't', 'x', $this->units['PU1']);
        $alerts->raise($this->campaign->id, 'irev_missing', 'medium', 'irev:3', 't', 'x', $this->units['PU3']);
        $this->travel(6)->seconds();

        $this->assertSame(['The result for Unit 3, in LGA L2 is not on IReV.'],
            array_column($this->getJson("/api/v1/voice/feed?after={$cursor}")->json('items'), 'text'));
    }

    public function test_the_race_is_announced_when_it_changes_and_not_otherwise(): void
    {
        foreach (['PU1', 'PU2', 'PU3', 'PU4', 'PU5', 'PU6'] as $code) {
            $this->submitResult($code, ['A' => 50, 'B' => 30, 'C' => 20], 100);
        }
        $race = app(RaceWatch::class);
        $campaign = Campaign::find($this->campaign->id);
        $t = now();

        $this->assertNull($race->run($campaign, $t)); // the lead must hold for a few minutes first
        $this->assertSame('Early results are in. We lead, with 50 percent of the votes counted so far.', $race->run($campaign, $t->copy()->addMinutes(3)));
        $this->assertNull($race->run($campaign, $t->copy()->addMinutes(4))); // race news is spaced out
        $this->assertSame('Every polling unit has reported. We lead with 50 percent, and a quarter of the vote in 3 of 3 LGAs.', $race->run($campaign, $t->copy()->addMinutes(9)));
        foreach ([15, 30, 60] as $m) {
            $this->assertNull($race->run($campaign, $t->copy()->addMinutes($m)));
        }
        $this->assertSame(2, Announcement::where('kind', 'race')->count());
    }

    public function test_the_sample_plays_and_the_command_room_knows_the_voice_is_on(): void
    {
        $this->actingAsUser($this->staff(Role::Verifier));
        $this->getJson('/api/v1/me')->assertJsonPath('rules.voice', true);
        $clip = $this->getJson('/api/v1/voice/sample')->assertOk()->json('items.0');
        $this->get($clip['audio'])->assertOk()->assertHeader('Content-Type', 'audio/wav');

        $this->actingAsUser($this->staff(Role::Finance));
        $this->getJson('/api/v1/voice/feed')->assertForbidden();
    }

    public function test_kokoro_being_down_does_not_stop_an_incident_being_reported(): void
    {
        $this->kokoroUp = false;
        $this->reportIncident('PU1', 'materials_late', 'low');
        $this->assertSame('Incident reported. Materials late at Unit 1, in LGA L1.', Announcement::value('text'));

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->get(URL::temporarySignedRoute('voice.audio', now()->addMinute(), ['t' => 'Hello'], absolute: false))->assertStatus(503);
    }
}
