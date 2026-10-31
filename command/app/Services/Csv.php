<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Reads a CSV with a header row into associative rows keyed by lower-case column name. */
final class Csv
{
    /**
     * @param  list<string>  $required  columns that must be in the header
     * @return \Generator<int, array<string, string>> line number => row
     */
    public static function rows(string $path, array $required): \Generator
    {
        $handle = fopen($path, 'r');
        $first = fgets($handle) ?: '';
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // Excel's byte-order mark
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv($first));
        if ($missing = array_diff($required, $header)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The CSV header is missing: '.implode(', ', $missing).'.']);
        }

        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || implode('', $cells) === '') {
                continue;
            }
            yield $line => array_combine($header, array_slice(array_pad(array_map(fn ($c) => self::clean((string) $c), $cells), count($header), ''), 0, count($header)));
        }
        fclose($handle);
    }

    /** A cell as text: trimmed, and ="01-01-001" (how our templates keep Excel from changing codes) unwrapped. */
    public static function clean(string $value): string
    {
        $value = trim($value);

        return preg_match('/^="(.*)"$/s', $value, $m) ? trim($m[1]) : $value;
    }

    /**
     * A code compared without the leading zeros a spreadsheet may drop: "01-01-001", "1-1-1" and "01-1-001" match.
     */
    public static function codeKey(?string $code): string
    {
        return implode('-', array_map(fn ($part) => ltrim($part, '0') ?: '0', explode('-', strtoupper(trim((string) $code)))));
    }

    /** A value written so spreadsheets keep it as text (leading zeros, codes that look like dates). */
    public static function text(?string $value): string
    {
        return $value === null || $value === '' ? '' : '="'.str_replace('"', '', $value).'"';
    }

    public static function truthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'y', 'yes', 'true', 'reserve', 'backup'], true);
    }
}
