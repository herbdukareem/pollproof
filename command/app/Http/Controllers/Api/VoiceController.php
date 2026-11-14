<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Lga;
use App\Services\Voice\Kokoro;
use App\Services\Voice\Speech;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The command room's spoken announcements. A screen with the voice on asks for the feed every
 * few seconds and plays the clips it returns, in order.
 *
 * Announcements are read in windows: everything that arrives within WINDOW seconds of the first
 * one is held until the window closes, then said together: one sentence on its own, a short
 * count and each sentence for a few, and for a burst the most urgent ones plus a summary of the
 * rest by kind and place ("Also: 37 units with no agent checked in, across 9 LGAs").
 */
class VoiceController extends Controller
{
    public const WINDOW = 5;

    // Anything older than this when a screen first sees it is not read out: a laptop that slept
    // through the afternoon shouldn't wake up and recite it.
    public const FRESH_MINUTES = 10;

    // Up to this many in one window are each read in full; more are summarised.
    public const IN_FULL = 3;

    // In a summarised window, this many of the most urgent are still read in full.
    public const URGENT_IN_FULL = 2;

    private const KINDS = ['incident', 'alert', 'escalation', 'race'];

    // How each kind of announcement is counted in a summary: [one, many].
    private const COUNTED = [
        'agent_absent' => ['unit with no agent checked in', 'units with no agent checked in'],
        'checkin_review' => ['check-in to confirm', 'check-ins to confirm'],
        'overvote' => ['overvote', 'overvotes'],
        'irev_missing' => ['result missing from IReV', 'results missing from IReV'],
        'irev_mismatch' => ['IReV difference', 'IReV differences'],
        'collation_mismatch' => ['collation mismatch', 'collation mismatches'],
        'result_flagged' => ['flagged result', 'flagged results'],
        'agent_unavailable' => ['agent who can\'t make it', 'agents who can\'t make it'],
        'verify_backlog' => ['note on the verification queue', 'notes on the verification queue'],
        'failed_logins' => ['run of failed sign-ins', 'runs of failed sign-ins'],
    ];

    private const NUMBERS = [1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];

    public function __construct(private readonly Kokoro $kokoro) {}

    public function feed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'kinds' => ['nullable', 'string', 'max:60'],
        ]);
        if (! $this->kokoro->enabled()) {
            return response()->json(['enabled' => false, 'cursor' => null, 'items' => []]);
        }

        $scoped = $this->scope($request);
        // A screen just switched on starts from now: it hears what happens next, not the backlog.
        if (! isset($data['after'])) {
            return response()->json(['enabled' => true, 'cursor' => (int) (clone $scoped)->max('id'), 'items' => []]);
        }

        $new = (clone $scoped)->where('id', '>', $data['after'])->orderBy('id')->limit(500)->get();
        $closed = $this->closedWindows($new, now());
        $cursor = (int) ($closed->flatten()->max('id') ?? $data['after']);

        $kinds = array_intersect(explode(',', (string) ($data['kinds'] ?? implode(',', self::KINDS))), self::KINDS);
        $fresh = now()->subMinutes(self::FRESH_MINUTES);
        $items = [];
        foreach ($closed as $window) {
            $wanted = $window->filter(fn (Announcement $a) => in_array($a->kind, $kinds, true) && $a->created_at->gte($fresh))->values();
            array_push($items, ...$this->compose($wanted));
        }

        return response()->json(['enabled' => true, 'cursor' => $cursor, 'items' => $items]);
    }

    /** The audio for one sentence the server wrote (the link is signed, so nothing else can be said). */
    public function audio(Request $request): Response
    {
        $text = (string) $request->query('t', '');
        abort_if($text === '' || ! $this->kokoro->enabled(), 404);
        try {
            $path = $this->kokoro->audio($text);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The voice server did not answer. Check that Kokoro is running.'], 503);
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** A sentence to check the voice works, from the voice settings in the top bar. */
    public function sample(): JsonResponse
    {
        abort_unless($this->kokoro->enabled(), 404);

        return response()->json(['items' => [$this->clip(null, 'sample', 'low', 'This is PollProof. Voice announcements are on for this screen.', null, 0)]]);
    }

    private function scope(Request $request)
    {
        $user = $request->user();

        return Announcement::where('campaign_id', $user->campaign_id)
            ->when($user->hasRole(Role::WardSupervisor), fn ($q) => $q->where(fn ($w) => $w->whereNull('lga_id')->orWhere('ward_id', $user->ward_id)))
            ->when($user->hasRole(Role::LgaSupervisor), fn ($q) => $q->where(fn ($w) => $w->whereNull('lga_id')->orWhere('lga_id', $user->lga_id)));
    }

    /**
     * Split announcements into windows: a window opens at the first one and takes everything
     * that arrives within WINDOW seconds of it. A window still open at $now is held back.
     *
     * @return Collection<int, Collection<int, Announcement>>
     */
    public function closedWindows(Collection $rows, Carbon $now): Collection
    {
        $windows = collect();
        $current = null;
        $opened = null;
        foreach ($rows->sortBy('id') as $a) {
            if ($current && $a->created_at->lt($opened->copy()->addSeconds(self::WINDOW))) {
                $current->push($a);

                continue;
            }
            $current = collect([$a]);
            $opened = $a->created_at;
            $windows->push(['opened' => $opened, 'rows' => $current]);
        }

        return $windows
            ->filter(fn ($w) => $w['opened']->copy()->addSeconds(self::WINDOW)->lte($now))
            ->map(fn ($w) => $w['rows'])
            ->values();
    }

    /** The clips that say one window, most urgent first, then the race news. */
    public function compose(Collection $window): array
    {
        $group = $window->first()?->id ?? 0;
        $race = $window->where('kind', 'race')->values();
        $rest = $window->reject(fn ($a) => $a->kind === 'race')->sortBy([
            fn ($a, $b) => $this->rank($a) <=> $this->rank($b),
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();

        $clips = [];
        $n = $rest->count();
        if ($n === 1) {
            $clips[] = $this->present($rest[0], $group);
        } elseif ($n > 1 && $n <= self::IN_FULL) {
            $clips[] = $this->clip(null, 'intro', $this->top($rest), $this->count($n).' new '.$this->noun($rest).'.', null, $group);
            foreach ($rest as $a) {
                $clips[] = $this->present($a, $group);
            }
        } elseif ($n > self::IN_FULL) {
            $clips[] = $this->clip(null, 'intro', $this->top($rest), $this->count($n).' new '.$this->noun($rest).' in the last few seconds.', null, $group);
            $urgent = $rest->filter(fn ($a) => $this->rank($a) <= 1)->take(self::URGENT_IN_FULL);
            foreach ($urgent as $a) {
                $clips[] = $this->present($a, $group);
            }
            $others = $rest->reject(fn ($a) => $urgent->contains('id', $a->id));
            if ($others->isNotEmpty()) {
                $clips[] = $this->clip(null, 'summary', 'low', $this->summary($others, $urgent->isNotEmpty()), $this->linkFor($others), $group);
            }
        }
        foreach ($race as $a) {
            $clips[] = $this->present($a, $group);
        }

        return $clips;
    }

    /** "Also: 37 units with no agent checked in, across 9 LGAs; 4 check-ins to confirm; and 1 overvote, in Bida." */
    private function summary(Collection $rows, bool $also): string
    {
        $groups = $rows->groupBy('topic')->sortByDesc(fn ($g) => $g->count());
        $lgaNames = Lga::whereIn('id', $rows->pluck('lga_id')->filter()->unique())->pluck('name', 'id');
        $parts = [];
        foreach ($groups->take(3) as $topic => $g) {
            $c = $g->count();
            [$one, $many] = self::COUNTED[$topic] ?? $this->incidentWords($topic, $g->first()->kind);
            $parts[] = "{$c} ".($c === 1 ? $one : $many).$this->where($g, $lgaNames);
        }
        $left = $groups->slice(3)->sum(fn ($g) => $g->count());
        if ($left) {
            $parts[] = "{$left} ".($left === 1 ? 'other' : 'others');
        }
        $list = count($parts) > 1 ? implode('; ', array_slice($parts, 0, -1)).'; and '.end($parts) : $parts[0];

        return ($also ? 'Also: ' : '').$list.'. See the alerts page.';
    }

    /** Where a group of announcements is: one or two LGAs by name, more as a count. */
    private function where(Collection $g, Collection $lgaNames): string
    {
        $lgas = $g->pluck('lga_id')->filter()->unique()->values();
        if ($lgas->isEmpty() || $lgas->count() < $g->count() && $g->contains(fn ($a) => $a->lga_id === null)) {
            return '';
        }

        return match ($lgas->count()) {
            1 => ', in '.Speech::plain((string) $lgaNames[$lgas[0]]),
            2 => ', in '.Speech::plain((string) $lgaNames[$lgas[0]]).' and '.Speech::plain((string) $lgaNames[$lgas[1]]),
            default => ", across {$lgas->count()} LGAs",
        };
    }

    private function incidentWords(string $topic, string $kind): array
    {
        if ($kind === 'incident' && isset(Speech::INCIDENTS[$topic]) && $topic !== 'other') {
            $label = mb_strtolower(Speech::INCIDENTS[$topic]);
            $label = str_replace('bvas', 'BVAS', $label);

            return ["{$label} report", "{$label} reports"];
        }

        return $kind === 'incident' ? ['other incident', 'other incidents'] : ['other alert', 'other alerts'];
    }

    private function rank(Announcement $a): int
    {
        return match (true) {
            $a->kind === 'escalation' => 0,
            $a->severity === 'high' => 1,
            $a->severity === 'medium' => 2,
            default => 3,
        };
    }

    private function top(Collection $rows): string
    {
        return $rows->contains(fn ($a) => $this->rank($a) <= 1) ? 'high' : 'medium';
    }

    private function noun(Collection $rows): string
    {
        return match (true) {
            $rows->every(fn ($a) => $a->kind === 'incident') => 'incidents',
            $rows->every(fn ($a) => $a->kind !== 'incident') => 'alerts',
            default => 'reports',
        };
    }

    private function count(int $n): string
    {
        return self::NUMBERS[$n] ?? (string) $n;
    }

    private function linkFor(Collection $rows): string
    {
        return $rows->every(fn ($a) => $a->kind === 'incident') ? '/incidents' : '/alerts';
    }

    private function present(Announcement $a, int $group): array
    {
        return $this->clip($a->id, $a->kind, $a->severity, $a->text, $a->link, $group);
    }

    private function clip(?int $id, string $kind, string $severity, string $text, ?string $link, int $group): array
    {
        return [
            'id' => $id,
            'group' => $group,
            'kind' => $kind,
            'severity' => $severity,
            'text' => $text,
            'link' => $link,
            'audio' => URL::temporarySignedRoute('voice.audio', now()->addMinutes(30), ['t' => $text], absolute: false),
        ];
    }
}
