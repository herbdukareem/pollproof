<?php

namespace App\Ai;

use NeuronAI\StructuredOutput\SchemaProperty;

/** One party's votes as announced. */
class HeardVote
{
    #[SchemaProperty(description: 'The party code exactly as given in the list of parties on the ballot, e.g. "A" or "APC".', required: true)]
    public string $party;

    #[SchemaProperty(description: 'The number of votes announced for this party, as a whole number.', required: true)]
    public int $votes;
}
