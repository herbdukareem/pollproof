<?php

namespace App\Services;

/**
 * Parses the fallback SMS format agents use when there is no data:
 *   R 004 AC448 A214 B187 C22 D9 X6
 * R <unit code> AC<accredited> <party code><votes>... X<rejected>
 * Party codes are letters only; the unit code may be numeric or alphanumeric.
 */
final class SmsResultParser
{
    /** @return array{unit: string, accredited: int, rejected: int, votes: array<string,int>}|null */
    public static function parse(string $text): ?array
    {
        $tokens = preg_split('/\s+/', strtoupper(trim($text)));
        if (count($tokens) < 3 || $tokens[0] !== 'R') {
            return null;
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

        return ['unit' => $unit, 'accredited' => $accredited, 'rejected' => $rejected, 'votes' => $votes];
    }
}
