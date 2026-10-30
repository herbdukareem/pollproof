<?php

namespace App\Services\Ussd;

use App\Models\CheckIn;
use App\Models\Party;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\IncidentReporter;
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

    /** @var Collection<int, Party> */
    private Collection $parties;

    private ?ResultSubmission $current = null;

    private ?array $resume = null;

    private string $ip = '';

    public function __construct(
        private readonly ResultRecorder $recorder,
        private readonly AuditLogger $audit,
        private readonly IncidentReporter $incidents,
    ) {}

    /** @return string the reply, starting with "CON " (wait for an answer) or "END " (close the session) */
    public function handle(User $agent, string $sessionId, string $text, string $ip = ''): string
    {
        $this->agent = $agent;
        $this->unit = $agent->pollingUnit()->with('ward.lga')->firstOrFail();
        $this->parties = Party::where('campaign_id', $agent->campaign_id)->orderBy('sort_order')->orderBy('code')->get()->values();
        $this->current = ResultSubmission::where('polling_unit_id', $this->unit->id)->where('is_current', true)->first();
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
        if ($inputs && in_array($state['screen'], self::ENTRY_SCREENS, true) && $state['screen'] !== 'acc') {
            Cache::put($this->draftKey(), ['unit_id' => $this->unit->id, 'state' => $state + ['error' => null]], now()->addMinutes(30));
        }

        return 'CON '.$this->render($state);
    }

    // ---- Answers -----------------------------------------------------------------------------

    private function step(array $s, string $in, bool $live): array
    {
        $s['error'] = null;
        $n = $this->parties->count();

        switch ($s['screen']) {
            case 'main':
                return match ($in) {
                    '1' => $this->resume
                        ? $this->resume['state']
                        : ($this->current ? ['screen' => 'existing', 'data' => [], 'error' => null] : ['screen' => 'acc', 'data' => [], 'error' => null]),
                    '2' => ['end' => $this->statusText()],
                    '3' => $this->startCheckIn(),
                    '4' => ['screen' => 'incident_type', 'data' => [], 'error' => null],
                    '0' => ['end' => 'Goodbye. Dial again any time to send your result.'],
                    default => ['error' => 'Choose 1, 2, 3 or 4.'] + $s,
                };

            case 'existing':
                return match ($in) {
                    '1' => ['screen' => 'reason', 'data' => [], 'error' => null],
                    '0' => ['screen' => 'main', 'data' => [], 'error' => null],
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

        $screen = match ($s['screen']) {
            'main' => "PollProof · {$this->agent->code}\n{$unit} ".Str::limit($this->unit->name, 30, '.')."\n"
                .($this->resume ? '1. Continue '.(isset($this->resume['state']['data']['reason']) ? 'correction' : 'result').' ('.$this->doneSteps($this->resume['state']).'/'.$steps.' done)' : ($this->current ? '1. Correct my result' : '1. Send result'))
                ."\n2. Result status\n3. I am at my unit\n4. Report incident\n0. Exit",
            'existing' => "{$unit} already has a result: total {$this->current->total_cast}, ref {$this->current->fingerprint()}, ".$this->statusWord($this->current).".\n1. Send a correction\n0. Back",
            'reason' => "Why correct it?\n".collect(self::CORRECTION_REASONS)->map(fn ($r, $k) => "{$k}. {$r}")->implode("\n")."\n0. Back",
            'acc' => 'Step 1/'.$steps.": ACCREDITED voters for {$unit}\n(copy from the EC8A)",
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
        $head = 'Check before sending'.(isset($d['reason']) ? ' (correction)' : '').":\n{$this->unit->code} · Accredited {$d['acc']}\n";
        $tail = "Rejected {$d['rej']} · Total cast {$cast}\n"
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

        return ($result->supersedes_id ? 'Correction sent' : 'Result sent')." for {$this->unit->code}.\nTotal cast {$result->total_cast}. Ref {$result->fingerprint()}\nA verifier will check it. Send the EC8A photo from the app when you have data.";
    }

    private function cancel(): string
    {
        Cache::forget($this->draftKey());

        return 'Cancelled. Nothing was sent.';
    }

    private function startCheckIn(): array
    {
        $latest = CheckIn::where('user_id', $this->agent->id)->where('polling_unit_id', $this->unit->id)->latest('id')->first();
        if ($latest?->counts()) {
            return ['end' => "You are checked in at {$this->unit->code} since ".$latest->captured_at->timezone(config('pollproof.timezone'))->format('g:i a').'.'];
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
        if (! $this->current) {
            return "No result yet for {$this->unit->code}.\nDial again and choose 1 to send it.";
        }
        $r = $this->current;
        $at = $r->created_at->timezone(config('pollproof.timezone'))->format('g:i a');
        $text = "{$this->unit->code} result\nTotal cast {$r->total_cast} · Ref {$r->fingerprint()}\nReceived {$at} by ".strtoupper($r->channel)."\nStatus: ".ucfirst($this->statusWord($r));
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

    private function doneSteps(array $state): int
    {
        return match ($state['screen']) {
            'acc' => 0,
            'party' => 1 + count($state['data']['votes'] ?? []),
            'rejected' => 1 + $this->parties->count(),
            default => $this->parties->count() + 2,
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

        if (! $draft || $draft['unit_id'] !== $this->unit->id) {
            return null;
        }
        // The unit got a result some other way (the app, or another session) after this one was started.
        $supersedes = $draft['state']['data']['supersedes'] ?? null;

        return $supersedes === ($this->current?->id) ? $draft : null;
    }
}
