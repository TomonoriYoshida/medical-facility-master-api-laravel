<?php

namespace App\Services\Mhlw;

use Carbon\CarbonImmutable;

final readonly class ResolvedMhlwDatasetLink
{
    public function __construct(
        public string $url,
        public string $filename,
        public CarbonImmutable $publishedOn,
    ) {}
}
