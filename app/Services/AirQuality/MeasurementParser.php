<?php

namespace App\Services\AirQuality;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class MeasurementParser
{
    public function __construct(private Geography $geography) {}

    public function live(array $r): array
    {
        if (($r['sensor']['sensor_type']['name'] ?? null) !== 'SDS011' || ($r['location']['country'] ?? null) !== 'BG' || ($r['location']['indoor'] ?? 0)) {
            throw new InvalidArgumentException('unsupported_or_indoor');
        }
        $v = [];
        foreach ($r['sensordatavalues'] ?? [] as $x) {
            $v[$x['value_type'] ?? ''] = $x['value'] ?? null;
        }

        return $this->parse(['sensor_id' => $r['sensor']['id'] ?? null, 'location' => $r['location']['id'] ?? null, 'lat' => $r['location']['latitude'] ?? null, 'lon' => $r['location']['longitude'] ?? null, 'timestamp' => $r['timestamp'] ?? null, 'P2' => $v['P2'] ?? null, 'P1' => $v['P1'] ?? null]);
    }

    public function parse(array $r): array
    {
        foreach (['sensor_id', 'location', 'lat', 'lon', 'timestamp', 'P2'] as $k) {
            if (! isset($r[$k]) || $r[$k] === '') {
                throw new InvalidArgumentException('missing_'.$k);
            }
        }
        foreach (['sensor_id', 'location'] as $k) {
            if (! ctype_digit((string) $r[$k]) || (int) $r[$k] <= 0) {
                throw new InvalidArgumentException('invalid_identifier');
            }
        }
        foreach (['lat', 'lon', 'P2'] as $k) {
            if (! is_numeric($r[$k]) || ! is_finite((float) $r[$k])) {
                throw new InvalidArgumentException('invalid_numeric');
            }
        }
        if (! $this->geography->contains((float) $r['lat'], (float) $r['lon'])) {
            throw new InvalidArgumentException('outside_bulgaria');
        }
        $pm25 = (float) $r['P2'];
        $pm10 = isset($r['P1']) && $r['P1'] !== '' ? $r['P1'] : null;
        if ($pm25 > 9999999 || ($pm10 !== null && is_numeric($pm10) && (float) $pm10 > 9999999)) {
            throw new InvalidArgumentException('concentration_overflow');
        }
        if ($pm25 < 0 || ($pm10 !== null && (! is_numeric($pm10) || ! is_finite((float) $pm10) || (float) $pm10 < 0))) {
            throw new InvalidArgumentException('invalid_concentration');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z|\+00:00)?$/', (string) $r['timestamp'])) {
            throw new InvalidArgumentException('invalid_timestamp');
        }
        try {
            $at = CarbonImmutable::parse($r['timestamp'], 'UTC')->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('invalid_timestamp');
        }
        if ($at->format('Y-m-d H:i:s') !== str_replace('T', ' ', preg_replace('/(?:Z|\+00:00)$/', '', (string) $r['timestamp']))) {
            throw new InvalidArgumentException('invalid_timestamp');
        }
        if ($at->year < 2015 || $at->greaterThan(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw new InvalidArgumentException('timestamp_out_of_range');
        }

        return ['external_sensor_id' => (int) $r['sensor_id'], 'external_location_id' => (int) $r['location'], 'latitude' => (float) $r['lat'], 'longitude' => (float) $r['lon'], 'measured_at' => $at->format('Y-m-d H:i:sP'), 'local_date' => $at->setTimezone('Europe/Sofia')->toDateString(), 'pm25' => $pm25, 'pm10' => $pm10 === null ? null : (float) $pm10, 'is_suspicious' => $pm25 > 999.9 || ($pm10 !== null && ((float) $pm10 > 1999.9 || $pm25 > (float) $pm10))];
    }
}
