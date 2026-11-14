<?php

namespace App\Services\Voice;

use App\Models\Campaign;
use App\Services\TallyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * How the race stands, said only when it changes. Runs every minute from pollproof:watch.
 *
 * It speaks when the lead changes hands, when we cross (or fall back below) the 25% rule, and
 * when another quarter of the polling units has reported. A change has to hold for STEADY
 * minutes before it is said, so a lead that flips back and forth on the next result isn't
 * announced twice, and race news is at least GAP minutes apart.
 */
final class RaceWatch
{
    public const STEADY = 3;

    public const GAP = 5;

    // Nothing is said until this share of units (and at least MIN_UNITS) has reported.
    public const START_SHARE = 0.05;

    public const MIN_UNITS = 10;

    public function __construct(private readonly TallyService $tally, private readonly Announcer $announcer, private readonly Kokoro $kokoro) {}

    public function run(Campaign $campaign, ?Carbon $now = null): ?string
    {
        if (! $this->kokoro->enabled()) {
            return null;
        }
        $now ??= now();
        $s = $this->tally->summary($campaign);
        $enough = max(min(self::MIN_UNITS, $s['units']), (int) ceil($s['units'] * self::START_SHARE));
        if ($s['units'] === 0 || $s['units_reported'] < $enough) {
            return null;
        }
        $ours = collect($s['candidates'])->firstWhere('is_ours', true);
        if (! $ours) {
            return null;
        }

        $key = "voice:race:{$campaign->id}";
        $state = Cache::get($key, []);
        $t = $now->getTimestamp();

        // What the race looks like now, and how long each part has looked this way.
        $leader = $s['candidates'][0]['votes'] > 0 ? $s['candidates'][0]['party'] : null;
        $cleared = $ours['lgas_meeting_threshold'] >= $s['lgas_required'];
        foreach (['leader' => $leader, 'cleared' => $cleared] as $part => $value) {
            if (! array_key_exists("{$part}_now", $state) || $state["{$part}_now"] !== $value) {
                $state["{$part}_now"] = $value;
                $state["{$part}_since"] = $t;
            }
        }
        $steady = fn (string $part) => $t - $state["{$part}_since"] >= self::STEADY * 60;
        $quiet = $t - ($state['said_at'] ?? 0) >= self::GAP * 60;
        $quarter = intdiv($s['units_reported'] * 4, $s['units']) * 25; // 0, 25, 50, 75 or 100

        $said = null;
        if ($quiet && $steady('leader') && $leader && ($state['leader_said'] ?? null) !== $leader) {
            $said = $this->lead($campaign->id, $s, $ours, $state['leader_said'] ?? null, $t);
            $state['leader_said'] = $leader;
        } elseif ($quiet && $steady('cleared') && array_key_exists('cleared_said', $state) && $state['cleared_said'] !== $cleared) {
            $said = $this->spread($campaign->id, $s, $ours, $cleared, $t);
            $state['cleared_said'] = $cleared;
        } elseif ($quiet && array_key_exists('leader_said', $state) && $quarter >= 25 && $quarter > ($state['quarter_said'] ?? 0)) {
            $said = $this->progress($campaign->id, $s, $ours, $quarter);
            $state['quarter_said'] = $quarter;
            $state['cleared_said'] ??= $cleared;
        }
        // The first time we cross the line it is news; being under it when counting starts isn't.
        if (! array_key_exists('cleared_said', $state) && $cleared && $steady('cleared') && $quiet && ! $said) {
            $said = $this->spread($campaign->id, $s, $ours, true, $t);
            $state['cleared_said'] = true;
        }
        if ($said) {
            $state['said_at'] = $t;
        }
        Cache::put($key, $state, now()->addDays(3));

        return $said;
    }

    private function lead(int $campaignId, array $s, array $ours, ?string $before, int $t): ?string
    {
        $top = $s['candidates'][0];
        $pct = $this->pct($ours['votes'], $s);
        if ($before === null) {
            $text = $top['party'] === $ours['party']
                ? "Early results are in. We lead, with {$pct} percent of the votes counted so far."
                : 'Early results are in. '.$this->name($top).' leads. We have '.$pct.' percent of the votes counted so far.';
        } elseif ($top['party'] === $ours['party']) {
            $text = "We have taken the lead, with {$pct} percent of the votes counted.";
        } elseif ($before === $ours['party']) {
            $behind = $top['votes'] - $ours['votes'];
            $text = $this->name($top)." has taken the lead. We are {$behind} votes behind.";
        } else {
            return null; // the lead changed between two other parties: not news for us
        }

        return $this->announcer->race($campaignId, 'lead', "lead:{$top['party']}:{$t}", $text)?->text;
    }

    private function spread(int $campaignId, array $s, array $ours, bool $cleared, int $t): ?string
    {
        $n = $ours['lgas_meeting_threshold'];
        $text = $cleared
            ? "We now have a quarter of the vote in {$n} of {$s['lga_count']} LGAs. That clears the {$s['lgas_required']} the constitution requires."
            : "We have fallen to {$n} LGAs with a quarter of the vote. We need {$s['lgas_required']}.";

        return $this->announcer->race($campaignId, 'spread', 'spread:'.($cleared ? 'met' : 'short').":{$t}", $text)?->text;
    }

    private function progress(int $campaignId, array $s, array $ours, int $quarter): ?string
    {
        $share = match ($quarter) {
            25 => 'A quarter of all polling units have reported.',
            50 => 'Half of all polling units have reported.',
            75 => 'Three quarters of all polling units have reported.',
            default => 'Every polling unit has reported.',
        };
        $top = $s['candidates'][0];
        $standing = $top['party'] === $ours['party']
            ? 'We lead with '.$this->pct($ours['votes'], $s).' percent'
            : $this->name($top).' leads. We have '.$this->pct($ours['votes'], $s).' percent';
        $spread = "a quarter of the vote in {$ours['lgas_meeting_threshold']} of {$s['lga_count']} LGAs";

        return $this->announcer->race($campaignId, 'progress', "progress:{$quarter}", "{$share} {$standing}, and {$spread}.")?->text;
    }

    private function pct(int $votes, array $s): int
    {
        $all = array_sum(array_column($s['candidates'], 'votes'));

        return $all > 0 ? (int) round(100 * $votes / $all) : 0;
    }

    /** Short party names are said as they are; long ones by their code. */
    private function name(array $candidate): string
    {
        $name = trim((string) ($candidate['name'] ?? ''));

        return $name !== '' && str_word_count($name) <= 3 ? $name : $candidate['party'];
    }
}
