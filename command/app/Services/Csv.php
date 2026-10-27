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
            yield $line => array_combine($header, array_slice(array_pad(array_map('trim', $cells), count($header), ''), 0, count($header)));
        }
        fclose($handle);
    }

    public static function truthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'y', 'yes', 'true', 'reserve', 'backup'], true);
    }
}
