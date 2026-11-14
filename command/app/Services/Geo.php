<?php

namespace App\Services;

final class Geo
{
    private const EARTH_RADIUS_M = 6371000;

    /** Great-circle distance in metres between two WGS84 points. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round(self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Whether a check-in at this distance is inside the unit area. The position must be within the
     * radius, and either the GPS is good, or it is rougher (up to twice the accepted error) but its
     * whole margin of error, the circle the phone says the agent is somewhere in, lies inside the
     * radius: 28 m away with a 48 m margin is inside a 100 m area wherever in that circle the agent
     * really is. A fix worse than that is too vague to trust. The app uses the same rule.
     */
    public static function insideArea(int $distance, int|float $accuracy, int $radius, int $maxAccuracy): bool
    {
        return $distance <= $radius
            && ($accuracy <= $maxAccuracy || ($accuracy <= 2 * $maxAccuracy && $distance + $accuracy <= $radius));
    }
}
