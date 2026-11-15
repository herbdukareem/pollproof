<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Neuron AI's message store for Ask PollProof (see NeuronAI\Chat\History\EloquentMessageStore). */
class AssistantMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'meta' => 'array', 'archived_at' => 'datetime'];
    }
}
