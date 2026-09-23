<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;

/**
 * One download link resolved from a bureau's index page HTML, ready to be
 * fetched by DownloadRhbDatasets. $url is already absolute (a
 * BureauLinkResolver is responsible for resolving any relative href
 * against its bureau's base_url).
 */
final readonly class ResolvedRhbDatasetLink
{
    public function __construct(
        public RhbCategory $category,
        public string $url,
        public string $filename,
        public CarbonImmutable $publishedOn,
    ) {}
}
