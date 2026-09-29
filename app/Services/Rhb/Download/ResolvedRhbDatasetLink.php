<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use Carbon\CarbonImmutable;

/**
 * One download link resolved from a bureau's index page HTML, ready to be
 * fetched by DownloadRhbDatasets. $url is already absolute (a
 * BureauLinkResolver is responsible for resolving any relative href
 * against its bureau's base_url).
 *
 * $prefectureCode is set only when the link's file covers that single
 * prefecture (Kyushu publishes one zip per prefecture), which lets an
 * out-of-scope prefecture's file be skipped without downloading it. Null
 * means the file bundles the bureau's prefectures (or the prefecture could
 * not be told from the page), so it is always downloaded.
 */
final readonly class ResolvedRhbDatasetLink
{
    public function __construct(
        public RhbCategory $category,
        public string $url,
        public string $filename,
        public CarbonImmutable $publishedOn,
        public ?string $prefectureCode = null,
    ) {}
}
