<?php

namespace Tests\Feature;

use App\Ai\Ai;
use App\Ai\Tools\AgentLookup;
use App\Ai\Tools\UnitsNeedingAttention;
use App\Enums\Role;
use App\Models\Alert;
use App\Models\AssistantThread;
use App\Models\ResultFlag;
use App\Models\VideoCheck;
use App\Models\VideoClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** AI checks of announcement videos, and Ask PollProof, with Neuron's fake provider in place of OpenAI. */
class AiTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    private FakeAIProvider $transcriber;

    private FakeAIProvider $reader;

    private FakeAIProvider $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pollproof.evidence_disk' => 'local', 'pollproof.ai.enabled' => true, 'pollproof.ai.ffmpeg' => '/nonexistent/ffmpeg']);
        $this->buildElection();
        $this->transcriber = new FakeAIProvider;
        $this->reader = new FakeAIProvider;
        $this->assistant = new FakeAIProvider;
        $this->app->instance(Ai::class, new class($this) extends Ai
        {
            public function __construct(private readonly AiTest $test) {}

            public function enabled(): bool
            {
                return true;
            }

            public function transcriber(): AIProviderInterface
            {
                return $this->test->fake('transcriber');
            }

            public function reader(): AIProviderInterface
            {
                return $this->test->fake('reader');
            }

            public function assistant(): AIProviderInterface
            {
                return $this->test->fake('assistant');
            }
        });
    }

    public function fake(string $which): FakeAIProvider
    {
        return $this->{$which};
    }

    /** An agent at PU1 sends a signed result, then a video of the announcement. */
    private function announce(array $heard, string $transcript = 'Party A two hundred and ten, party B ninety.'): VideoCheck
    {
        $this->submitResult('PU1', ['A' => 210, 'B' => 90, 'C' => 5], 320, 3);
        $this->transcriber->addResponses(new AssistantMessage($transcript));
        $this->reader->addResponses(new AssistantMessage(json_encode($heard)));
        $bytes = 'video bytes';
        $id = $this->postJson('/api/v1/videos', [
            'client_id' => (string) Str::uuid(), 'kind' => 'result', 'mime' => 'video/mp4', 'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'captured_at' => now()->toIso8601String(),
        ])->json('id');
        $this->call('POST', "/api/v1/videos/{$id}/chunk", [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_OFFSET' => '0'], $bytes)->assertJson(['complete' => true]);

        return VideoCheck::where('video_clip_id', $id)->firstOrFail();
    }

    public function test_an_announcement_video_that_differs_is_flagged_for_review_and_a_person_confirms_it(): void
    {
        $check = $this->announce(['is_result_announcement' => true, 'accredited' => 320, 'votes' => [['party' => 'A', 'votes' => 120], ['party' => 'b', 'votes' => 90], ['party' => 'Z', 'votes' => 4]], 'confidence' => 'high']);

        $this->assertSame(['done', 'differs'], [$check->status, $check->outcome], (string) $check->error);
        $this->assertSame(['A' => ['heard' => 120, 'signed' => 210]], $check->differences);
        // Party codes are matched whatever their case; parties not on the ballot are dropped.
        $this->assertSame(['A', 'B'], array_column($check->heard['votes'], 'party'));
        $this->assertStringContainsString('two hundred', $check->transcript);
        $this->transcriber->assertCallCount(1);
        $alert = Alert::where('type', 'ai_mismatch')->firstOrFail();
        $this->assertSame('PU1: announcement video differs from the signed result', $alert->title);

        $this->actingAsUser($this->staff(Role::Verifier));
        $this->getJson('/api/v1/ai-checks')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('counts.review', 1)
            ->assertJsonPath('data.0.result.votes.A', 210)
            ->assertJsonPath('data.0.heard.votes.0.votes', 120);
        $this->getJson('/api/v1/nav-counts')->assertJsonPath('ai', 1);

        $this->postJson("/api/v1/ai-checks/{$check->id}/review", ['action' => 'confirm', 'note' => 'Clear on the video.'])->assertOk()->assertJsonPath('review', 'confirmed');
        $flag = ResultFlag::firstOrFail();
        $this->assertSame('figures_differ', $flag->reason);
        $this->assertStringContainsString('A: heard 120, signed 210', $flag->note);
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->getJson('/api/v1/ai-checks?view=reviewed')->assertJsonPath('total', 1);
    }

    public function test_a_corrected_result_that_matches_the_video_settles_the_check(): void
    {
        $check = $this->announce(['is_result_announcement' => true, 'votes' => [['party' => 'A', 'votes' => 200]], 'confidence' => 'medium']);
        $this->assertSame('differs', $check->outcome);

        // The agent corrects the sheet to what was announced.
        $agent = $this->units['PU1']->agent;
        [$device, $sk] = $this->deviceFor($agent);
        $this->actingAsUser($agent);
        $this->postJson('/api/v1/results', $this->signedResult($device, $sk, 'PU1', ['A' => 200, 'B' => 90, 'C' => 5], 320, 3, $check->result_submission_id))->assertCreated();

        $this->assertSame('match', $check->fresh()->outcome);
        $this->assertSame('resolved', Alert::where('type', 'ai_mismatch')->firstOrFail()->status);
    }

    public function test_a_video_without_an_announcement_is_unclear_and_other_kinds_are_not_checked(): void
    {
        $check = $this->announce(['is_result_announcement' => false, 'votes' => [], 'confidence' => 'low'], 'People arguing in the queue.');
        $this->assertSame('unclear', $check->outcome);
        $this->assertSame(0, Alert::where('type', 'ai_mismatch')->count());

        $this->postJson('/api/v1/videos', ['client_id' => (string) Str::uuid(), 'kind' => 'other', 'mime' => 'video/mp4', 'size' => 1, 'sha256' => hash('sha256', 'x'), 'captured_at' => now()->toIso8601String()]);
        $id = VideoClip::where('kind', 'other')->value('id');
        $this->call('POST', "/api/v1/videos/{$id}/chunk", [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_OFFSET' => '0'], 'x');
        $this->assertSame(1, VideoCheck::count());
    }

    public function test_ask_pollproof_answers_with_its_tools_and_keeps_each_persons_conversations(): void
    {
        $this->submitResult('PU1', ['A' => 210, 'B' => 90, 'C' => 5], 320, 3);
        $dg = $this->actingAsUser($this->staff(Role::Dg));
        $this->assistant->addResponses(
            new ToolCallMessage(null, [new ToolCall('race_standings', 'call_1', [])]),
            new AssistantMessage('We lead the governorship by 120 votes with 1 of 6 units in.'),
        );

        $res = $this->postJson('/api/v1/assistant/messages', ['message' => 'How are we doing?'])->assertOk()
            ->assertJsonPath('reply.text', 'We lead the governorship by 120 votes with 1 of 6 units in.');
        $threadId = $res->json('thread.id');
        $this->assistant->assertCallCount(2);
        // The tool's answer went back to the model.
        $this->assertStringContainsString('Governorship', json_encode($this->assistant->getRecorded()[1]->messages));

        $this->getJson("/api/v1/assistant/threads/{$threadId}")->assertOk()
            ->assertJsonPath('messages.0.text', 'How are we doing?')
            ->assertJsonPath('messages.1.text', 'We lead the governorship by 120 votes with 1 of 6 units in.')
            ->assertJsonCount(2, 'messages');
        $this->getJson('/api/v1/assistant/threads')->assertJsonPath('threads.0.title', 'How are we doing?');

        $this->actingAsUser($this->staff(Role::SituationRoom));
        $this->getJson("/api/v1/assistant/threads/{$threadId}")->assertNotFound();
        $this->actingAsUser($dg);
        $this->deleteJson("/api/v1/assistant/threads/{$threadId}")->assertOk();
        $this->assertSame(0, AssistantThread::count());
    }

    public function test_the_tools_see_only_a_supervisors_own_area(): void
    {
        $this->agentFor('PU1');
        $this->agentFor('PU3', ['name' => 'Musa Garba']);
        $sup = $this->staff(Role::WardSupervisor, ['ward_id' => $this->wards['L1']->id]);

        $noAgent = (new UnitsNeedingAttention($sup))('no_agent');
        $this->assertSame(1, $noAgent['count']); // PU2 only; PU4..PU6 are in other wards
        $this->assertFalse((new AgentLookup($sup))('Musa Garba')['found']);
        $this->assertSame('AG-PU3', (new AgentLookup($this->staff(Role::Dg)))('Musa')['people'][0]['code']);
    }
}
