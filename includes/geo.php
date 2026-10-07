<?php
declare(strict_types=1);

/** Great-circle distance in metres between two WGS84 points (haversine). */
function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371008.8;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Among the given locations, find the nearest one and whether the point lies inside its radius.
 * Returns ['location' => row|null, 'distance' => float|null, 'inside' => bool].
 */
function nearestLocation(array $locations, float $lat, float $lng): array
{
    $best = null;
    $bestDist = null;
    foreach ($locations as $loc) {
        $d = distanceMetres($lat, $lng, (float)$loc['latitude'], (float)$loc['longitude']);
        if ($bestDist === null || $d < $bestDist) {
            $best = $loc;
            $bestDist = $d;
        }
    }
    return [
        'location' => $best,
        'distance' => $bestDist,
        'inside' => $best !== null && $bestDist <= (float)$best['radius_m'],
    ];
}
