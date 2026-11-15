<?php

namespace App\Ai;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

/** What the AI made out of a result announcement: only figures actually spoken, never guesses. */
class HeardResult
{
    #[SchemaProperty(description: 'True if the recording contains an official reading out of polling unit results (figures per party).', required: true)]
    public bool $is_result_announcement;

    #[SchemaProperty(description: 'Number of accredited voters, if it was announced. Null if not heard.', required: false)]
    public ?int $accredited = null;

    #[SchemaProperty(description: 'Number of rejected ballots, if it was announced. Null if not heard.', required: false)]
    public ?int $rejected = null;

    #[SchemaProperty(description: 'Total valid votes, if it was announced. Null if not heard.', required: false)]
    public ?int $total_valid = null;

    #[SchemaProperty(description: 'Total votes cast, if it was announced. Null if not heard.', required: false)]
    public ?int $total_cast = null;

    /** @var HeardVote[] */
    #[SchemaProperty(description: 'Votes announced for each party that was read out. Leave out parties whose figure was not heard.', required: true, anyOf: [HeardVote::class])]
    #[ArrayOf(HeardVote::class, allowEmpty: true)]
    public array $votes = [];

    #[SchemaProperty(description: 'How sure you are of the figures: "high" if each was spoken clearly, "medium" if some were unclear, "low" if mostly guesswork.', required: true)]
    public string $confidence = 'low';

    #[SchemaProperty(description: 'The polling unit name or code if it was spoken, otherwise null.', required: false)]
    public ?string $unit_heard = null;

    #[SchemaProperty(description: 'One or two sentences on anything a reviewer should know: figures corrected while reading, numbers said in words, parties not on the list, noise. Null if nothing.', required: false)]
    public ?string $notes = null;
}
