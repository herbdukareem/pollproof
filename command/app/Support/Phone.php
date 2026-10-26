<?php

namespace App\Support;

/**
 * Nigerian mobile numbers. Stored in local form (08031234567) because that is how
 * agents type them; converted to international form (2348031234567) for SMS gateways.
 */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^0[789][01]\d{8}$/', $digits) ? $digits : null;
    }

    public static function international(string $phone): string
    {
        $local = self::normalize($phone) ?? preg_replace('/\D+/', '', $phone);

        return str_starts_with($local, '0') ? '234'.substr($local, 1) : $local;
    }

    /** 0803 *** 2290 */
    public static function masked(?string $phone): ?string
    {
        return $phone ? substr($phone, 0, 4).' *** '.substr($phone, -4) : null;
    }
}
