<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Server-side sorting for lists built in PHP, matching the command room's sortable headers.
 * The request sends ?sort=<column>&dir=asc|desc; each column says how to read its value and
 * which way it sorts on the first click. Blanks always go last; equal rows keep their order.
 *
 *   ListSort::apply($rows, $request, [
 *       'latest' => [fn ($r) => $r['at'], 'desc'],   // the first column is the default
 *       'code'   => [fn ($r) => $r['code'], 'asc'],
 *   ]);
 */
final class ListSort
{
    /** Validation rules for the sort and dir parameters. */
    public static function rules(array $columns): array
    {
        return [
            'sort' => ['nullable', 'in:'.implode(',', array_keys($columns))],
            'dir' => ['nullable', 'in:asc,desc'],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @param  array<string, array{0: ?callable, 1: string}>  $columns  key => [value getter or null to keep the order, first direction]
     * @param  array{sort?: ?string, dir?: ?string}|Request  $input
     * @return Collection<int, mixed>
     */
    public static function apply(Collection $rows, mixed $input, array $columns): Collection
    {
        $sort = is_array($input) ? ($input['sort'] ?? null) : $input->input('sort');
        $dir = is_array($input) ? ($input['dir'] ?? null) : $input->input('dir');
        $sort = isset($columns[$sort]) ? $sort : array_key_first($columns);
        [$get, $first] = $columns[$sort];
        $sign = (in_array($dir, ['asc', 'desc'], true) ? $dir : $first) === 'asc' ? 1 : -1;
        if ($get === null) {
            return $sign === 1 ? $rows->values() : $rows->reverse()->values();
        }

        $keyed = $rows->values()->map(fn ($row, $i) => [self::comparable($get($row)), $i, $row])->all();
        usort($keyed, function ($a, $b) use ($sign) {
            $na = $a[0] === null || $a[0] === '';
            $nb = $b[0] === null || $b[0] === '';
            if ($na || $nb) {
                return $na && $nb ? $a[1] <=> $b[1] : ($na ? 1 : -1);
            }
            $c = is_string($a[0]) || is_string($b[0]) ? strnatcasecmp((string) $a[0], (string) $b[0]) : $a[0] <=> $b[0];

            return $c !== 0 ? $c * $sign : $a[1] <=> $b[1];
        });

        return collect(array_column($keyed, 2));
    }

    private static function comparable(mixed $v): mixed
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v->getTimestamp(),
            is_bool($v) => (int) $v,
            is_string($v) && preg_match('/^\d{4}-\d\d-\d\dT/', $v) === 1 => strtotime($v) ?: $v,
            default => $v,
        };
    }
}
