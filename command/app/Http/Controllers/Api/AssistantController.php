<?php

namespace App\Http\Controllers\Api;

use App\Ai\Ai;
use App\Ai\CommandAssistant;
use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use NeuronAI\Chat\History\EloquentMessageStore;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/** Ask PollProof: each person's own conversations with the assistant. */
class AssistantController extends Controller
{
    public function __construct(private readonly Ai $ai, private readonly AuditLogger $audit) {}

    public function threads(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $this->ai->enabled(),
            'threads' => AssistantThread::where('user_id', $request->user()->id)->latest('updated_at')->take(30)->get(['id', 'title', 'updated_at']),
        ]);
    }

    public function show(Request $request, AssistantThread $thread): JsonResponse
    {
        abort_unless($thread->user_id === $request->user()->id, 404);

        return response()->json(['thread' => $thread->only(['id', 'title', 'updated_at']), 'messages' => $this->messages($thread->id)]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->ai->enabled(), 503, 'Ask PollProof needs an OpenAI key on the server (OPENAI_API_KEY).');
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'thread_id' => ['nullable', 'string'],
        ]);
        $thread = ($data['thread_id'] ?? null)
            ? AssistantThread::where('user_id', $user->id)->findOrFail($data['thread_id'])
            : AssistantThread::create(['campaign_id' => $user->campaign_id, 'user_id' => $user->id, 'title' => Str::limit(trim(preg_replace('/\s+/', ' ', $data['message'])), 80)]);

        try {
            $state = app(CommandAssistant::class)->forUser($user, $thread->id)->chat(new UserMessage(trim($data['message'])));
            $reply = trim((string) $state->getMessage()->getContent());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'thread' => $thread->only(['id', 'title']),
                'message' => 'Ask PollProof could not answer just now. Try again in a moment; if it keeps failing, check the OpenAI key and model settings.',
            ], 502);
        }
        $thread->touch();
        $this->audit->log($user, 'assistant.asked', null, ['thread' => $thread->id, 'length' => mb_strlen($data['message'])], $request->ip());

        return response()->json(['thread' => $thread->only(['id', 'title', 'updated_at']), 'reply' => ['role' => 'assistant', 'text' => $reply]]);
    }

    public function destroy(Request $request, AssistantThread $thread): JsonResponse
    {
        abort_unless($thread->user_id === $request->user()->id, 404);
        (new EloquentMessageStore(AssistantMessage::class))->clear($thread->id);
        $thread->delete();

        return response()->json(['deleted' => true]);
    }

    /** The conversation as the person saw it: their questions and the answers, without the tool calls in between. */
    private function messages(string $threadId): array
    {
        return collect((new EloquentMessageStore(AssistantMessage::class))->loadAll($threadId))
            ->reject(fn (Message $m) => $m instanceof ToolCallMessage || $m instanceof ToolResultMessage)
            ->map(fn (Message $m) => ['role' => $m->getRole() === 'user' ? 'user' : 'assistant', 'text' => trim((string) $m->getContent())])
            ->filter(fn ($m) => $m['text'] !== '')
            ->values()->all();
    }
}
