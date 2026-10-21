<?php

namespace Tests\Unit;

use App\Services\AirQuality\Geography;
use PHPUnit\Framework\TestCase;

class GeographyTest extends TestCase
{
    public function test_zero_distance(): void
    {
        $this->assertSame(0.0, Geography::distance(42.7, 23.3, 42.7, 23.3));
    }

    public function test_one_degree_on_equator(): void
    {
        $this->assertEqualsWithDelta(111.195, Geography::distance(0, 0, 0, 1), 0.001);
    }

    public function test_distance_is_symmetric(): void
    {
        $this->assertEqualsWithDelta(Geography::distance(42.7, 23.3, 42.15, 24.75), Geography::distance(42.15, 24.75, 42.7, 23.3), 1e-9);
    }
}
