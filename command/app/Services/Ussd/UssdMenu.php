<?php

namespace App\Services\Ussd;

use App\Models\CheckIn;
use App\Models\Election;
use App\Models\Party;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CheckInReview;
use App\Services\IncidentReporter;
use App\Services\Races;
use App\Services\ResultRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The USSD menu for polling-unit agents, for phones without data.
 *
 * Africa's Talking sends every answer of the session so far in `text`, joined with "*". The menu is
 * rebuilt from the start on every request by replaying those answers, so a wrong entry just re-asks
 * the question and a repeated request gives the same screen. Only the newest answer can save
 * anything (send a result, check in, report an incident).
 *
 * A result being typed in is also kept for 30 minutes, so an agent whose session drops can dial
 * again and carry on from where they stopped.
 *
 * Where the unit is in more than one race (governorship and state assembly, say), sending a
 * result starts by choosing which race's sheet it is.
 */
final class UssdMenu
{
    /** Most networks show about 182 characters on one screen. */
    public const MAX_SCREEN = 182;

    public const INCIDENT_TYPES = [
        '1' => ['disruption', 'Violence or disruption'],
        '2' => ['vote_buying', 'Vote buying'],
        '3' => ['bvas_problem', 'BVAS not working'],
        '4' => ['agent_turned_away', 'Agent turned away'],
        '5' => ['materials_late', 'Materials late'],
        '6' => ['other', 'Something else'],
    ];

    public const SEVERITIES = [
        '1' => ['high', 'Urgent: danger or voting stopped'],
        '2' => ['medium', 'Serious'],
        '3' => ['low', 'Minor'],
    ];

    public const CORRECTION_REASONS = [
        '1' => 'I typed a figure wrongly',
        '2' => 'Figures were recounted',
        '3' => 'Wrong sheet was used',
        '4' => 'Verifier sent it back',
    ];

    private const ENTRY_SCREENS = ['acc', 'party', 'rejected', 'confirm'];

    private User $agent;

    private PollingUnit $unit;

    /** @var Collection<int, Election> the races at this unit */
    private Collection $races;

    /** The race whose result is being sent or looked at. */
    private ?Election $race = null;

    /** @var Collection<int, Party> the race's ballot */
    private Collection $parties;

    private ?ResultSubmission $current = null;

    private ?array $resume = null;

    private string $ip = '';

    public function __construct(
        private readonly ResultRecorder $recorder,
        private readonly AuditLogger $audit,
        private readonly IncidentReporter $incidents,
        private readonly CheckInReview $reviews,
    ) {}

    /** @return string the reply, starting with "CON " (wait for an answer) or "END " (close the session) */
    public function handle(User $agent, string $sessionId, string $text, string $ip = ''): string
    {
        $this->agent = $agent;
        $this->unit = $agent->pollingUnit()->with('ward.lga')->firstOrFail();
        $this->races = Races::atUnit($this->unit);
        if ($this->races->isEmpty() && ($primary = $agent->campaign->primaryElection())) {
            $this->races = collect([$primary->load('parties')]);
        }
        $this->parties = collect();
        if ($this->races->count() === 1) {
            $this->useRace($this->races->first());
        }
        $this->ip = $ip;

        // What the agent had typed in an earlier, dropped session, fixed for the whole of this one.
        $snapshotKey = "ussd:resume:{$sessionId}";
        if ($text === '') {
            Cache::put($snapshotKey, $this->draft() ?? false, now()->addMinutes(15));
        }
        $this->resume = Cache::get($snapshotKey) ?: null;

        $inputs = $text === '' ? [] : explode('*', $text);
        $state = ['screen' => 'main', 'data' => [], 'error' => null];
        $last = count($inputs) - 1;
        foreach ($inputs as $i => $input) {
            $state = $this->step($state, trim($input), $i === $last);
            if (isset($state['end'])) {
                return 'END '.$state['end'];
            }
        }
        // Keep what has been typed once there is something to lose.
        if ($inputs && $this->race && in_array($state['screen'], self::ENTRY_SCREENS, true) && $state['screen'] !== 'acc') {
            Cache::put($this->draftKey(), ['unit_id' => $this->unit->id, 'race' => $this->race->id, 'state' => ['error' => null] + $state], now()->addMinutes(30));
        }

        return 'CON '.$this->render($state);
    }

    // ---- Answers -----------------------------------------------------------------------------

    private function useRace(Election $race): void
    {
        $this->race = $race;
        $this->parties = $race->parties->values();
        $this->current = ResultSubmission::where('polling_unit_id', $this->unit->id)->where('election_id', $race->id)->where('is_current', true)->first();
    }

    /** After the race is known: correct the existing result, or start a new one. */
    private function startResult(): array
    {
        return $this->current ? ['screen' => 'existing', 'data' => [], 'error' => null] : ['screen' => 'acc', 'data' => [], 'error' => null];
    }

    private function step(array $s, string $in, bool $live): array
    {
        $s['error'] = null;
        $n = $this->parties->count();

        switch ($s['screen']) {
            case 'main':
                return match ($in) {
                    '1' => match (true) {
                        (bool) $this->resume => $this->resumeDraft(),
                        $this->races->count() > 1 => ['screen' => 'race', 'data' => [], 'error' => null],
                        default => $this->startResult(),
                    },
                    '2' => ['end' => $this->statusText()],
                    '3' => $this->startCheckIn(),
                    '4' => ['screen' => 'incident_type', 'data' => [], 'error' => null],
                    '0' => ['end' => 'Goodbye. Dial again any time to send your result.'],
                    default => ['error' => 'Choose 1, 2, 3 or 4.'] + $s,
                };

            case 'race':
                if ($in === '0') {
                    return ['screen' => 'main', 'data' => [], 'error' => null];
                }
                $race = $this->races->values()->get((int) $in - 1);
                if (! ctype_digit($in) || ! $race) {
                    return ['error' => 'Choose 1 to '.$this->races->count().'.'] + $s;
                }
                if ($race->isDeclared()) {
                    return ['error' => "{$race->code} is declared. Choose another."] + $s;
                }
                $this->useRace($race);

                return $this->startResult();

            case 'existing':
                return match ($in) {
                    '1' => ['screen' => 'reason', 'data' => [], 'error' => null],
                    '0' => ['screen' => $this->races->count() > 1 ? 'race' : 'main', 'data' => [], 'error' => null],
                    default => ['error' => 'Choose 1 or 0.'] + $s,
                };

            case 'reason':
                if ($in === '0') {
                    return ['screen' => 'existing', 'data' => [], 'error' => null];
                }
                if (! isset(self::CORRECTION_REASONS[$in])) {
                    return ['error' => 'Choose 1 to 4.'] + $s;
                }

                return ['screen' => 'acc', 'data' => ['reason' => self::CORRECTION_REASONS[$in], 'supersedes' => $this->current?->id], 'error' => null];

            case 'acc':
                if (($v = $this->number($in)) === null) {
                    return ['error' => 'Enter numbers only, e.g. 412.'] + $s;
                }
                if ($this->unit->registered_voters && $v > $this->unit->registered_voters) {
                    return ['error' => 'More than the '.number_format($this->unit->registered_voters).' registered here. Check the sheet.'] + $s;
                }
                $s['data']['acc'] = $v;
                $s['data']['votes'] = [];
                $s['screen'] = $n ? 'party' : 'rejected';

                return $s;

            case 'party':
                if (($v = $this->number($in)) === null) {
                    return ['error' => 'Enter numbers only, e.g. 0 or 215.'] + $s;
                }
                $code = $this->parties[count($s['data']['votes'])]->code;
                $s['data']['votes'][$code] = $v;
                if (count($s['data']['votes']) >= $n) {
                    $s['screen'] = 'rejected';
                }

                return $s;

            case 'rejected':
                if (($v = $this->number($in)) === null) {
                    return ['error' => 'Enter numbers only, e.g. 6.'] + $s;
                }
                $s['data']['rej'] = $v;
                $s['screen'] = 'confirm';

                return $s;

            case 'confirm':
                return match ($in) {
                    '1' => ['end' => $live ? $this->submit($s['data']) : 'Session ended.'],
                    '2' => ['screen' => 'acc', 'data' => array_intersect_key($s['data'], array_flip(['reason', 'supersedes'])), 'error' => null],
                    '0' => ['end' => $this->cancel()],
                    default => ['error' => 'Choose 1, 2 or 0.'] + $s,
                };

            case 'checkin':
                return match ($in) {
                    '1' => ['end' => $live ? $this->checkIn() : 'Session ended.'],
                    '0' => ['screen' => 'main', 'data' => [], 'error' => null],
                    default => ['error' => 'Choose 1 or 0.'] + $s,
                };

            case 'incident_type':
                if ($in === '0') {
                    return ['screen' => 'main', 'data' => [], 'error' => null];
                }
                if (! isset(self::INCIDENT_TYPES[$in])) {
                    return ['error' => 'Choose 1 to 6.'] + $s;
                }

                return ['screen' => 'incident_severity', 'data' => ['type' => $in], 'error' => null];

            case 'incident_severity':
                if ($in === '0') {
                    return ['screen' => 'incident_type', 'data' => [], 'error' => null];
                }
                if (! isset(self::SEVERITIES[$in])) {
                    return ['error' => 'Choose 1, 2 or 3.'] + $s;
                }
                $s['data']['severity'] = $in;
                $s['screen'] = 'incident_text';

                return $s;

            case 'incident_text':
                if ($in !== '1' && mb_strlen($in) < 3) {
                    return ['error' => 'Type a few words, or 1 to skip.'] + $s;
                }

                return ['end' => $live ? $this->reportIncident($s['data'], $in === '1' ? null : $in) : 'Session ended.'];
        }

        return ['end' => 'Something went wrong. Please dial again.'];
    }

    // ---- Screens -----------------------------------------------------------------------------

    private function render(array $s): string
    {
        $err = $s['error'] ? $s['error']."\n" : '';
        $unit = $this->unit->code;
        $n = $this->parties->count();
        $steps = $n + 2;

        $race = $this->races->count() > 1 && $this->race ? "{$this->race->code} " : '';
        $screen = match ($s['screen']) {
            'main' => "PollProof, {$this->agent->code}\n{$unit} ".Str::limit($this->unit->name, 30, '.')."\n"
                .$this->firstOption()
                ."\n2. Result status\n3. I am at my unit\n4. Report incident\n0. Exit",
            'race' => "Which result sheet?\n".$this->races->values()->map(fn (Election $e, $i) => ($i + 1).'. '.Str::limit($e->name, 30, '.').($this->hasResult($e) ? ' (sent)' : ''))->implode("\n")."\n0. Back",
            'existing' => "{$race}{$unit} already has a result: total {$this->current->total_cast}, ref {$this->current->fingerprint()}, ".$this->statusWord($this->current).".\n1. Send a correction\n0. Back",
            'reason' => "Why correct it?\n".collect(self::CORRECTION_REASONS)->map(fn ($r, $k) => "{$k}. {$r}")->implode("\n")."\n0. Back",
            'acc' => 'Step 1/'.$steps.": ACCREDITED voters for {$race}{$unit}\n(copy from the EC8A)",
            'party' => $this->partyPrompt(count($s['data']['votes']), $steps),
            'rejected' => "Step {$steps}/{$steps}: REJECTED ballots",
            'confirm' => $this->summary($s['data']),
            'checkin' => "Confirm you are at {$unit} ".Str::limit($this->unit->name, 40, '.')." now?\n1. Yes, I am here\n0. Back",
            'incident_type' => "What happened?\n".collect(self::INCIDENT_TYPES)->map(fn ($t, $k) => "{$k}. {$t[1]}")->implode("\n")."\n0. Back",
            'incident_severity' => "How serious?\n".collect(self::SEVERITIES)->map(fn ($t, $k) => "{$k}. {$t[1]}")->implode("\n")."\n0. Back",
            'incident_text' => "Describe it in a few words\n(or 1 to skip)",
        };

        return $err.$screen;
    }

    private function partyPrompt(int $k, int $steps): string
    {
        $party = $this->parties[$k];
        $name = $party->name !== "Party {$party->code}" ? ' ('.Str::limit($party->name, 24, '.').')' : '';

        return 'Step '.($k + 2)."/{$steps}: votes for {$party->code}{$name}";
    }

    private function summary(array $d): string
    {
        $valid = array_sum($d['votes'] ?? []);
        $cast = $valid + $d['rej'];
        $parties = collect($d['votes'] ?? [])->map(fn ($v, $c) => "{$c} {$v}")->implode(', ');
        $race = $this->races->count() > 1 ? "{$this->race->code} " : '';
        $head = 'Check before sending'.(isset($d['reason']) ? ' (correction)' : '').":\n{$race}{$this->unit->code}, accredited {$d['acc']}\n";
        $tail = "Rejected {$d['rej']}, total cast {$cast}\n"
            .($cast > $d['acc'] ? "! Total is more than accredited\n" : '')
            ."1. Send\n2. Start again\n0. Cancel";
        $text = $head.$parties."\n".$tail;
        if (mb_strlen($text) > self::MAX_SCREEN) {
            // Too many parties to list on one screen: show the total of valid votes instead.
            $ours = $this->parties->firstWhere('is_ours', true);
            $text = $head.count($d['votes'] ?? []).' parties, '.$valid.' valid'.($ours ? " ({$ours->code} {$d['votes'][$ours->code]})" : '')."\n".$tail;
        }

        return $text;
    }

    // ---- Actions -----------------------------------------------------------------------------

    private function submit(array $d): string
    {
        try {
            $result = $this->recorder->record(
                agent: $this->agent,
                unit: $this->unit,
                election: $this->race,
                votes: $d['votes'] ?? [],
                accredited: $d['acc'],
                rejected: $d['rej'],
                capturedAt: now()->utc()->toIso8601ZuluString(),
                supersedesId: $d['supersedes'] ?? null,
                correctionReason: isset($d['reason']) ? 'USSD: '.$d['reason'] : null,
                channel: 'ussd',
            );
        } catch (ValidationException $e) {
            return 'Not sent: '.Str::limit(collect($e->errors())->flatten()->first(), 120).' Dial again.';
        }
        Cache::forget($this->draftKey());
        $this->audit->log($this->agent, isset($d['reason']) ? 'result.corrected_ussd' : 'result.submitted_ussd', $result, ['payload_hash' => $result->payload_hash], $this->ip);

        $race = $this->races->count() > 1 ? "{$this->race->name} " : '';

        return ($result->supersedes_id ? 'Correction sent' : 'Result sent')." for {$race}{$this->unit->code}.\nTotal cast {$result->total_cast}. Ref {$result->fingerprint()}\nA verifier will check it. Send the EC8A photo from the app when you have data.";
    }

    private function cancel(): string
    {
        Cache::forget($this->draftKey());

        return 'Cancelled. Nothing was sent.';
    }

    private function startCheckIn(): array
    {
        // One check-in per working day: yesterday's doesn't count today, and a rejected one can be redone.
        $latest = CheckIn::where('user_id', $this->agent->id)->where('polling_unit_id', $this->unit->id)
            ->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED, CheckIn::PENDING_REVIEW])
            ->today()->latest('id')->first();
        if ($latest?->counts()) {
            return ['end' => "You are already checked in at {$this->unit->code} today, since ".$latest->captured_at->timezone(config('pollproof.timezone'))->format('g:i a').'. Nothing more to do.'];
        }
        if ($latest?->status === CheckIn::PENDING_REVIEW) {
            return ['end' => 'Your check-in is waiting for your supervisor to confirm. They may call you.'];
        }

        return ['screen' => 'checkin', 'data' => [], 'error' => null];
    }

    private function checkIn(): string
    {
        $checkIn = CheckIn::create([
            'campaign_id' => $this->agent->campaign_id,
            'user_id' => $this->agent->id,
            'polling_unit_id' => $this->unit->id,
            'channel' => 'ussd',
            'status' => CheckIn::PENDING_REVIEW,
            'captured_at' => now(),
        ]);
        $this->audit->log($this->agent, 'checkin.created', $checkIn, ['status' => $checkIn->status, 'channel' => 'ussd'], $this->ip);
        $this->reviews->waiting($checkIn);

        return "Check-in sent for {$this->unit->code}.\nUSSD has no GPS, so your supervisor will confirm it. They may call you.";
    }

    private function reportIncident(array $d, ?string $text): string
    {
        [$type, $label] = self::INCIDENT_TYPES[$d['type']];
        $severity = self::SEVERITIES[$d['severity']][0];
        $incident = $this->incidents->report($this->agent, $type, $severity, Str::limit($text ?? $label, 500).' (sent by USSD)', now()->toIso8601String());
        $this->audit->log($this->agent, 'incident.reported', $incident, ['type' => $type, 'severity' => $severity, 'channel' => 'ussd'], $this->ip);

        return "Incident reported (ref #{$incident->id}).\n".($severity === 'high' ? 'The situation room has been alerted. ' : '').'Stay safe. Your supervisor may call you.';
    }

    // ---- Helpers -----------------------------------------------------------------------------

    private function statusText(): string
    {
        if ($this->races->count() > 1) {
            // One line per race.
            $lines = $this->races->map(function (Election $e) {
                $r = ResultSubmission::where('polling_unit_id', $this->unit->id)->where('election_id', $e->id)->where('is_current', true)->first();

                return $r ? "{$e->code}: {$r->total_cast} cast, ".$this->statusWord($r) : "{$e->code}: not sent";
            });

            return Str::limit("{$this->unit->code} results\n".$lines->implode("\n"), self::MAX_SCREEN - 4, '');
        }
        if (! $this->current) {
            return "No result yet for {$this->unit->code}.\nDial again and choose 1 to send it.";
        }
        $r = $this->current;
        $at = $r->created_at->timezone(config('pollproof.timezone'))->format('g:i a');
        $text = "{$this->unit->code} result\nTotal cast {$r->total_cast}, ref {$r->fingerprint()}\nReceived {$at} by ".strtoupper($r->channel)."\nStatus: ".ucfirst($this->statusWord($r));
        if ($r->status === ResultSubmission::SENT_BACK) {
            $text .= "\n".Str::limit((string) $r->review_note, 60)."\nDial again, choose 1 to correct.";
        }

        return Str::limit($text, self::MAX_SCREEN - 4, '');
    }

    private function statusWord(ResultSubmission $r): string
    {
        return match ($r->status) {
            ResultSubmission::APPROVED => 'verified',
            ResultSubmission::SENT_BACK => 'sent back',
            default => 'waiting for verification',
        };
    }

    private function number(string $in): ?int
    {
        return preg_match('/^\d{1,5}$/', $in) ? (int) $in : null;
    }

    private function doneSteps(array $state, int $parties): int
    {
        return match ($state['screen']) {
            'acc' => 0,
            'party' => 1 + count($state['data']['votes'] ?? []),
            'rejected' => 1 + $parties,
            default => $parties + 2,
        };
    }

    private function draftKey(): string
    {
        return "ussd:draft:{$this->agent->id}";
    }

    /** A result the agent started in an earlier session, if it is still for their current unit. */
    private function draft(): ?array
    {
        $draft = Cache::get($this->draftKey());
        $race = $draft ? $this->races->firstWhere('id', $draft['race'] ?? null) : null;
        if (! $draft || $draft['unit_id'] !== $this->unit->id || ! $race) {
            return null;
        }
        // The unit got a result some other way (the app, or another session) after this one was started.
        $current = ResultSubmission::where('polling_unit_id', $this->unit->id)->where('election_id', $race->id)->where('is_current', true)->value('id');
        $supersedes = $draft['state']['data']['supersedes'] ?? null;

        return $supersedes === $current ? $draft : null;
    }

    private function resumeDraft(): array
    {
        $this->useRace($this->races->firstWhere('id', $this->resume['race']));

        return $this->resume['state'];
    }

    private function hasResult(Election $race): bool
    {
        return ResultSubmission::where('polling_unit_id', $this->unit->id)->where('election_id', $race->id)->where('is_current', true)->exists();
    }

    /** "1. Send result", "1. Correct my result", or "1. Continue result (3/6 done)". */
    private function firstOption(): string
    {
        if ($this->resume) {
            $race = $this->races->firstWhere('id', $this->resume['race']);
            $steps = $race->parties->count() + 2;
            $what = (isset($this->resume['state']['data']['reason']) ? 'correction' : 'result').($this->races->count() > 1 ? " {$race->code}" : '');

            return "1. Continue {$what} (".$this->doneSteps($this->resume['state'], $race->parties->count()).'/'.$steps.' done)';
        }
        if ($this->races->count() > 1) {
            return '1. Send a result';
        }

        return $this->current ? '1. Correct my result' : '1. Send result';
    }
}
