<?php

namespace App\Services\Voice;

use App\Models\Alert;
use App\Models\CollationEntry;
use App\Models\Incident;
use App\Models\IrevEntry;
use App\Models\Lga;
use App\Models\PollingUnit;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Models\Ward;

/**
 * The sentences the command room speaks. Written for the ear: places by name rather than unit
 * codes ("01-07-006" reads badly aloud), whole numbers, no symbols.
 */
final class Speech
{
    public const INCIDENTS = [
        'disruption' => 'Disruption',
        'vote_buying' => 'Vote buying',
        'bvas_problem' => 'BVAS problem',
        'agent_turned_away' => 'Agent turned away',
        'materials_late' => 'Materials late',
        'other' => 'Incident',
    ];

    // Abbreviations common in INEC polling-unit names, said in full.
    private const WORDS = [
        'PRI' => 'Primary', 'SCH' => 'School', 'SEC' => 'Secondary', 'JNR' => 'Junior', 'SNR' => 'Senior',
        'GOVT' => 'Government', 'GOV' => 'Government', 'MKT' => 'Market', 'OPP' => 'Opposite',
        'VILL' => 'Village', 'DIST' => 'District', 'HOSP' => 'Hospital', 'DISP' => 'Dispensary',
        'COMP' => 'Compound', 'STR' => 'Street', 'ST' => 'Street', 'RD' => 'Road', 'NO' => 'Number',
        'COMM' => 'Community', 'CTR' => 'Centre', 'HQ' => 'Headquarters', 'GEN' => 'General',
    ];

    public static function incident(Incident $incident): string
    {
        $what = self::INCIDENTS[$incident->type] ?? 'Incident';
        $where = $incident->pollingUnit ? ' at '.self::place($incident->pollingUnit) : '';

        return match ($incident->severity) {
            'high' => "High priority incident. {$what}{$where}.",
            default => "Incident reported. {$what}{$where}.",
        };
    }

    /** One sentence for an alert, or null for alerts the voice leaves to the screen. */
    public static function alert(Alert $alert, bool $escalation = false): ?string
    {
        $s = $alert->subject;
        $unit = self::unitOf($s);
        $at = $unit ? self::place($unit) : null;
        $data = $alert->data ?? [];

        $sentence = match ($alert->type) {
            'incident' => $s instanceof Incident ? self::incident($s) : null,
            'overvote' => $s instanceof CollationEntry
                ? 'Overvote on the '.self::sheet($s).'. Declared votes exceed accreditation.'
                : ($at ? "Overvote at {$at}. Votes cast exceed accredited voters." : null),
            'collation_mismatch' => $s instanceof CollationEntry ? 'Collation mismatch on the '.self::sheet($s).'. The declared figures differ from our units.' : null,
            'irev_missing' => $at ? "The result for {$at} is not on IReV." : null,
            'irev_mismatch' => $at ? "IReV differs from our signed result at {$at}." : null,
            'result_flagged' => $at ? "Result flagged at {$at}. ".self::flagReason($data['flag_id'] ?? null).'.' : null,
            'agent_unavailable' => $at ? "The agent for {$at} can't make election day. Send a reserve." : null,
            'agent_absent' => $at ? "No agent has checked in at {$at}." : null,
            'checkin_review' => $at ? "Check-in to confirm at {$at}." : null,
            'verify_backlog' => preg_match('/^\d+/', (string) $alert->title, $m) ? "{$m[0]} results are waiting to be verified." : null,
            'failed_logins' => $s instanceof User ? "Repeated failed sign-ins for {$s->name}." : null,
            default => null,
        } ?? self::plain((string) $alert->title).'.';

        return $escalation ? "Still not handled. {$sentence}" : $sentence;
    }

    /** "Etsugaie Market Square, in Agaie". */
    public static function place(PollingUnit $unit): string
    {
        $name = self::plain((string) $unit->name);
        $lga = $unit->ward?->lga?->name;

        return $lga ? "{$name}, in ".self::plain($lga) : $name;
    }

    /** A name as it should be read: title case if it was all capitals, abbreviations in full. */
    public static function plain(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text !== '' && mb_strtoupper($text) === $text && preg_match('/[A-Z]{4,}/', $text)) {
            $text = mb_convert_case(mb_strtolower($text), MB_CASE_TITLE);
        }
        $text = preg_replace_callback('/\b([A-Za-z]{2,4})\.(?=\s|$|,)/', fn ($m) => self::WORDS[strtoupper($m[1])] ?? $m[0], $text);

        // Unit codes and similar are read digit by digit as a number, not as a date or a sum.
        return preg_replace_callback('/\b\d{2}-\d{2}-\d{3}\b/', fn ($m) => 'unit '.str_replace('-', ' ', $m[0]), $text);
    }

    private static function unitOf(mixed $subject): ?PollingUnit
    {
        return match (true) {
            $subject instanceof PollingUnit => $subject,
            $subject instanceof Incident, $subject instanceof IrevEntry, $subject instanceof ResultSubmission => $subject->pollingUnit,
            $subject instanceof User => $subject->pollingUnit,
            default => null,
        };
    }

    private static function sheet(CollationEntry $entry): string
    {
        return match ($entry->level) {
            CollationEntry::WARD => ($w = Ward::with('lga')->find($entry->scope_id))
                ? self::plain($w->name).' ward collation sheet, in '.self::plain($w->lga->name)
                : 'ward collation sheet',
            CollationEntry::LGA => ($l = Lga::find($entry->scope_id)) ? self::plain($l->name).' LGA collation sheet' : 'LGA collation sheet',
            default => 'state collation sheet',
        };
    }

    private static function flagReason(?int $flagId): string
    {
        $flag = $flagId ? ResultFlag::find($flagId) : null;

        return $flag ? $flag->label() : 'A reviewer raised a concern';
    }
}
