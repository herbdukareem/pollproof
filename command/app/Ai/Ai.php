<?php

namespace App\Ai;

use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;

/**
 * The AI models PollProof uses, all through OpenAI and Neuron AI. One place to build them, so
 * tests can swap in Neuron's FakeAIProvider (bind a subclass of this in the container).
 */
class Ai
{
    public function enabled(): bool
    {
        return (bool) config('pollproof.ai.enabled');
    }

    /** Speech to text, for the sound of an announcement video. */
    public function transcriber(): AIProviderInterface
    {
        return new OpenAISpeechToText(
            key: (string) config('pollproof.ai.key'),
            model: (string) config('pollproof.ai.transcribe_model'),
            language: (string) config('pollproof.ai.language'),
        );
    }

    /** Reads the announced figures out of a transcript. */
    public function reader(): AIProviderInterface
    {
        return new OpenAIResponses(key: (string) config('pollproof.ai.key'), model: (string) config('pollproof.ai.model'));
    }

    /** Ask PollProof. */
    public function assistant(): AIProviderInterface
    {
        return new OpenAIResponses(key: (string) config('pollproof.ai.key'), model: (string) config('pollproof.ai.assistant_model'));
    }

    /** @return array<string, string> the models in use, recorded with each check */
    public function models(): array
    {
        return ['transcribe' => (string) config('pollproof.ai.transcribe_model'), 'read' => (string) config('pollproof.ai.model')];
    }
}
