<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * INEC's public polling-unit locator (https://cvr.inecnigeria.org/pu).
 *
 * The portal lists LGAs, wards (registration areas) and polling units from its PublicApi, and its
 * "Get directions" form answers with a redirect to Google Maps whose ?q= holds the unit's latitude
 * and longitude. Requests go out concurrently in batches (Http::pool), with retries and back-off,
 * so a whole state takes minutes without hammering the portal.
 *
 * The portal numbers states its own way: alphabetical with the FCT as 15 (Niger is 27). INEC's
 * delimitation codes put the FCT last (Niger is 26), which is the code used on result sheets.
 */
final class InecLocator
{
    public const BASE = 'https://cvr.inecnigeria.org';

    public const STATES = [
        1 => 'ABIA', 2 => 'ADAMAWA', 3 => 'AKWA IBOM', 4 => 'ANAMBRA', 5 => 'BAUCHI', 6 => 'BAYELSA', 7 => 'BENUE',
        8 => 'BORNO', 9 => 'CROSS RIVER', 10 => 'DELTA', 11 => 'EBONYI', 12 => 'EDO', 13 => 'EKITI', 14 => 'ENUGU',
        15 => 'FCT', 16 => 'GOMBE', 17 => 'IMO', 18 => 'JIGAWA', 19 => 'KADUNA', 20 => 'KANO', 21 => 'KATSINA',
        22 => 'KEBBI', 23 => 'KOGI', 24 => 'KWARA', 25 => 'LAGOS', 26 => 'NASARAWA', 27 => 'NIGER', 28 => 'OGUN',
        29 => 'ONDO', 30 => 'OSUN', 31 => 'OYO', 32 => 'PLATEAU', 33 => 'RIVERS', 34 => 'SOKOTO', 35 => 'TARABA',
        36 => 'YOBE', 37 => 'ZAMFARA',
    ];

    public function __construct(
        private readonly int $concurrency = 20,
        private readonly int $timeout = 20,
        private readonly int $retries = 3,
    ) {}

    /** The portal's state id from a name ("Niger") or an id ("27"). */
    public static function stateId(string $state): ?int
    {
        if (ctype_digit($state)) {
            return isset(self::STATES[(int) $state]) ? (int) $state : null;
        }
        $id = array_search(strtoupper(trim($state)), self::STATES, true);

        return $id === false ? null : $id;
    }

    /** INEC's delimitation code for the state, as printed on result sheets (Niger 26, FCT 37). */
    public static function delimitationCode(int $portalId): string
    {
        return sprintf('%02d', $portalId === 15 ? 37 : ($portalId > 15 ? $portalId - 1 : $portalId));
    }

    /**
     * Every LGA, ward and polling unit in a state. Wards for all LGAs are fetched at once, then
     * units for all wards at once (in batches).
     *
     * @return list<array{id: string, code: string, name: string, wards: list<array{id: string, code: string, name: string, pus: list<array{id: string, code: string, name: string}>}>}>
     */
    public function hierarchy(int $stateId, ?Closure $progress = null): array
    {
        $lgas = $this->labels($this->get('lgas/1/Search', ['state_id' => $stateId]));
        $wardsByLga = $this->fetchMany('wards/1/Search', 'local_government_id', array_keys($lgas));
        $progress?->__invoke('lgas', count($lgas));

        $wardIds = [];
        foreach ($wardsByLga as $wards) {
            $wardIds = array_merge($wardIds, array_keys($wards));
        }
        $pusByWard = $this->fetchMany('pus/1/Search', 'registration_area_id', $wardIds);
        $progress?->__invoke('wards', count($wardIds));

        $out = [];
        foreach ($lgas as $lgaId => $lgaLabel) {
            [$code, $name] = $this->split($lgaLabel);
            $lga = ['id' => (string) $lgaId, 'code' => $code, 'name' => $name, 'wards' => []];
            foreach ($wardsByLga[$lgaId] ?? [] as $wardId => $wardLabel) {
                [$wcode, $wname] = $this->split($wardLabel);
                $pus = [];
                foreach ($pusByWard[$wardId] ?? [] as $puId => $puLabel) {
                    [$pcode, $pname] = $this->split($puLabel);
                    $pus[] = ['id' => (string) $puId, 'code' => $pcode, 'name' => $pname];
                }
                usort($pus, fn ($a, $b) => strcmp($a['code'], $b['code']));
                $lga['wards'][] = ['id' => (string) $wardId, 'code' => $wcode, 'name' => $wname, 'pus' => $pus];
            }
            usort($lga['wards'], fn ($a, $b) => strcmp($a['code'], $b['code']));
            $out[] = $lga;
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return $out;
    }

    /**
     * Coordinates for many polling units, fetched concurrently in batches. Units INEC has no
     * location for come back as null. $done is called after each batch with the batch's results.
     *
     * @param  list<array{key: string, state: int, lga: string, ward: string, pu: string}>  $units
     * @return array<string, array{lat: float, lng: float}|null> keyed by each unit's key
     */
    public function coordinates(array $units, ?Closure $done = null): array
    {
        $results = [];
        $pending = $units;
        for ($round = 0; $round < $this->retries && $pending; $round++) {
            $failed = [];
            foreach (array_chunk($pending, $this->concurrency) as $batch) {
                $responses = Http::pool(fn (Pool $pool) => array_map(fn ($u) => $pool->as($u['key'])
                    ->withHeaders($this->headers())->timeout($this->timeout)->withoutRedirecting()->asForm()
                    ->post(self::BASE.'/pu_locator/index', [
                        'data[Search][state_id]' => $u['state'],
                        'data[Search][local_government_id]' => $u['lga'],
                        'data[Search][registration_area_id]' => $u['ward'],
                        'data[Search][polling_unit_id]' => $u['pu'],
                    ]), $batch));

                $batchResults = [];
                foreach ($batch as $u) {
                    $r = $responses[$u['key']] ?? null;
                    if (! $r instanceof Response || $r->status() === 429 || $r->serverError()) {
                        $failed[] = $u; // network error, rate limit or server error: try again later

                        continue;
                    }
                    $batchResults[$u['key']] = self::parseLocation((string) $r->header('Location'));
                }
                $results += $batchResults;
                $done?->__invoke($batchResults, count($failed));
            }
            $pending = $failed;
            if ($pending) {
                sleep(2 ** ($round + 1)); // back off before retrying the failures
            }
        }
        foreach ($pending as $u) {
            $results[$u['key']] ??= null;
        }

        return $results;
    }

    /** Latitude and longitude from INEC's redirect, e.g. https://maps.google.com/?q=9.0817,6.0093 */
    public static function parseLocation(string $location): ?array
    {
        if (! preg_match('/[?&](?:q|query|destination|daddr)=(-?\d+(?:\.\d+)?)\s*(?:,|%2C)\s*(-?\d+(?:\.\d+)?)/i', urldecode($location), $m)
            && ! preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $location, $m)) {
            return null;
        }
        [$lat, $lng] = [(float) $m[1], (float) $m[2]];

        // Inside Nigeria, and not the 0,0 a blank record gives.
        return $lat >= 4 && $lat <= 14 && $lng >= 2.5 && $lng <= 15 ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    /** @return array<string, string> id => "03 - BIDA" */
    private function get(string $endpoint, array $params): array
    {
        for ($i = 0; $i < $this->retries; $i++) {
            try {
                $r = Http::withHeaders($this->headers())->timeout($this->timeout)->get(self::BASE.'/PublicApi/'.$endpoint, $this->search($params));
                if ($r->successful()) {
                    return $this->labels($r->json());
                }
            } catch (Throwable) {
                // retry below
            }
            sleep(2 ** $i);
        }

        throw new \RuntimeException("INEC's portal did not answer {$endpoint} for ".json_encode($params).'.');
    }

    /** @return array<string, array<string, string>> parent id => children (id => label) */
    private function fetchMany(string $endpoint, string $param, array $ids): array
    {
        $out = [];
        $pending = $ids;
        for ($round = 0; $round < $this->retries && $pending; $round++) {
            $failed = [];
            foreach (array_chunk($pending, $this->concurrency) as $batch) {
                $responses = Http::pool(fn (Pool $pool) => array_map(fn ($id) => $pool->as((string) $id)
                    ->withHeaders($this->headers())->timeout($this->timeout)
                    ->get(self::BASE.'/PublicApi/'.$endpoint, $this->search([$param => $id])), $batch));
                foreach ($batch as $id) {
                    $r = $responses[(string) $id] ?? null;
                    $r instanceof Response && $r->successful() ? $out[$id] = $this->labels($r->json()) : $failed[] = $id;
                }
            }
            $pending = $failed;
            if ($pending) {
                sleep(2 ** ($round + 1));
            }
        }
        if ($pending) {
            throw new \RuntimeException(count($pending)." {$endpoint} requests kept failing (ids ".implode(', ', array_slice($pending, 0, 5)).'). Run again to resume.');
        }

        return $out;
    }

    /** The PublicApi answers [{ "id": "label", ..., "selected": ... }] or the same object unwrapped. */
    private function labels(mixed $json): array
    {
        $data = is_array($json) && array_is_list($json) ? ($json[0] ?? []) : ($json ?? []);

        return collect(is_array($data) ? $data : [])->except(['0', 'selected'])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')->all();
    }

    private function search(array $params): array
    {
        return collect($params)->mapWithKeys(fn ($v, $k) => ["data[Search][{$k}]" => $v])->all();
    }

    /** "03 - BIDA" => ["03", "BIDA"] */
    private function split(string $label): array
    {
        return preg_match('/^\s*(\d+)\s*-\s*(.*)$/s', $label, $m) ? [$m[1], trim($m[2])] : ['', trim($label)];
    }

    private function headers(): array
    {
        return ['User-Agent' => 'Mozilla/5.0 (PollProof polling-unit sync)', 'Accept' => 'application/json, text/html'];
    }
}
