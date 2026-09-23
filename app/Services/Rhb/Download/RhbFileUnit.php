<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;

/**
 * One "(bureau, category, prefecture) -> a readable local .xlsx path and
 * optional sheet name" unit, ready to be handed to
 * InsuredFacilityDatasetRowSource. Produced by a BundleExpander from one
 * downloaded artifact, which may itself bundle multiple such units (e.g. a
 * zip of per-prefecture files, or one file with a sheet per prefecture) --
 * always re-derived at import time, never persisted, so this state can
 * never drift from the actual downloaded file on disk.
 */
final readonly class RhbFileUnit
{
    public function __construct(
        public RhbBureau $bureau,
        public RhbCategory $category,
        public string $prefectureCode,
        public string $xlsxPath,
        public ?string $sheetName = null,
    ) {}
}
