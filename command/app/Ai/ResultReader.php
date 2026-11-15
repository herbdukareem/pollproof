<?php

namespace App\Ai;

use App\Models\Election;
use App\Models\PollingUnit;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

/**
 * Reads the figures out of the transcript of a result announcement. It is told the ballot so it
 * can match spoken party names to their codes, and to leave out anything it did not clearly hear:
 * a missing figure is better than a guessed one, since every difference is checked by a person.
 */
class ResultReader extends Agent
{
    public function __construct(private readonly Ai $ai)
    {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->ai->reader();
    }

    /** The reader for one race's ballot at one unit. */
    public function forRace(?Election $race, ?PollingUnit $unit): static
    {
        $ballot = $race?->parties()->get()
            ->map(fn ($p) => "- {$p->code}: {$p->name}".($p->pivot->candidate ? " (candidate {$p->pivot->candidate})" : ''))
            ->implode("\n") ?: '- (not known)';
        $where = $unit ? "{$unit->code} {$unit->name}" : 'a polling unit';

        // Each reading stands alone: a fresh thread, so no transcript ever sees another's.
        $this->setThreadId('read-'.bin2hex(random_bytes(8)));
        $this->setInstructions(<<<PROMPT
            You read transcripts of Nigerian election result announcements recorded on a party agent's phone.
            The recording is meant to be the presiding officer at {$where} announcing the {$race?->name} result (form EC8A).

            Parties on this ballot, as code: name:
            {$ballot}

            Rules:
            - Report only figures that are spoken in the transcript. Never work a figure out or guess it. Leave out a party whose votes you did not hear.
            - Numbers may be said in words, in English, Pidgin, Hausa or Yoruba ("two hundred and fourteen", "dari biyu da sha hudu"). Convert them to digits.
            - Match each spoken party name, abbreviation or candidate to the code in the list above, and use that code. Ignore parties not on the list, and say so in notes.
            - If a figure is corrected while being read, use the corrected figure and mention it in notes.
            - If the recording is not a result announcement (a queue, an argument, silence), set is_result_announcement to false and leave the figures empty.
            PROMPT);

        return $this;
    }
}
