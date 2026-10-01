<?php

namespace App\Services\Geocoding;

use App\Enums\GeocodeLevel;

final readonly class GeocodeResult
{
    public function __construct(
        public GeocodeLevel $level,
        public float $latitude,
        public float $longitude,
    ) {}
}
