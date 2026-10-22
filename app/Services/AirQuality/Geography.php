<?php

namespace App\Services\AirQuality;

use Illuminate\Support\Facades\DB;

class Geography
{
    private ?array $cities = null;

    private ?array $geometry = null;

    public function contains(float $lat, float $lon): bool
    {
        if (! is_finite($lat) || ! is_finite($lon) || $lat < 41 || $lat > 45 || $lon < 22 || $lon > 29) {
            return false;
        }
        $g = $this->geometry ??= json_decode(file_get_contents(database_path('geography/bulgaria.geojson')), true, 512, JSON_THROW_ON_ERROR)['geometry'];
        $polygons = $g['type'] === 'Polygon' ? [$g['coordinates']] : $g['coordinates'];
        foreach ($polygons as $p) {
            if (! $this->ring($lon, $lat, $p[0])) {
                continue;
            } $hole = false;
            foreach (array_slice($p, 1) as $r) {
                if ($this->ring($lon, $lat, $r)) {
                    $hole = true;
                }
            } if (! $hole) {
                return true;
            }
        }

return false;
    }

    private function ring(float $x, float $y, array $ring): bool
    {
        $in = false;
        for ($i = 0,$j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi,$yi] = $ring[$i];
            [$xj,$yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $in = ! $in;
            }
        }

return $in;
    }

    public static function distance(float $a, float $b, float $c, float $d): float
    {
        $h = sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2;

        return 6371 * 2 * asin(sqrt(min(1, $h)));
    }

    public function assign(float $lat, float $lon): array
    {
        $this->cities ??= DB::table('cities')->get()->all();
        $best = null;
        $distance = INF;
        foreach ($this->cities as $city) {
            $d = self::distance($lat, $lon, (float) $city->latitude, (float) $city->longitude);
            if ($d < $distance) {
                $best = $city;
                $distance = $d;
            }
        }

        $radius = $best ? min(config('air.max_distance_km'), max(2, sqrt(($best->population ?? 0) / 10000))) : 0;

        return ['city_id' => $distance <= $radius ? $best->id : null, 'assignment_method' => $distance <= $radius ? 'nearest_city_conservative' : 'unassigned', 'assignment_distance_km' => is_finite($distance) ? round($distance,4) : null];
    }
}
