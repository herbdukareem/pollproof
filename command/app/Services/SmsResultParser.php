<?php

namespace App\Services;

/**
 * Parses the fallback SMS format agents use when there is no data:
 *   R GOV 004 AC448 A214 B187 C22 D9 X6
 * R [race code] <unit code> AC<accredited> <party code><votes>... X<rejected>
 * The race code is needed only where the unit is in more than one race. Party codes are
 * letters only; the unit code may be numeric or alphanumeric.
 */
final class SmsResultParser
{
    /**
     * @param  list<string>  $raceCodes  the campaign's race codes, so "R GOV 004 …" reads GOV as the race
     * @return array{race: ?string, unit: string, accredited: int, rejected: int, votes: array<string,int>}|null
     */
    public static function parse(string $text, array $raceCodes = []): ?array
    {
        $tokens = preg_split('/\s+/', strtoupper(trim($text)));
        if (count($tokens) < 3 || $tokens[0] !== 'R') {
            return null;
        }
        $race = null;
        if (count($tokens) >= 4 && in_array($tokens[1], array_map('strtoupper', $raceCodes), true)) {
            $race = $tokens[1];
            array_splice($tokens, 1, 1);
        }
        $unit = $tokens[1];
        $accredited = null;
        $rejected = 0;
        $votes = [];
        foreach (array_slice($tokens, 2) as $token) {
            if (preg_match('/^AC(\d+)$/', $token, $m)) {
                $accredited = (int) $m[1];
            } elseif (preg_match('/^X(\d+)$/', $token, $m)) {
                $rejected = (int) $m[1];
            } elseif (preg_match('/^([A-Z]{1,8})(\d+)$/', $token, $m)) {
                $votes[$m[1]] = (int) $m[2];
            } else {
                return null;
            }
        }
        if ($accredited === null || ! $votes) {
            return null;
        }

        return ['race' => $race, 'unit' => $unit, 'accredited' => $accredited, 'rejected' => $rejected, 'votes' => $votes];
    }
}
