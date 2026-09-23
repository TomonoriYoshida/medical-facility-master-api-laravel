<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;

/**
 * Turns one downloaded artifact into a flat list of ready-to-process
 * units. Some bureaus bundle multiple prefectures into one download (a
 * zip of per-prefecture files, or one file with a sheet per prefecture);
 * this seam is what lets those bureaus be added later without touching
 * the Import/Sync layers or the Job that drives them.
 */
interface BundleExpanderInterface
{
    /**
     * @return list<RhbFileUnit>
     */
    public function expand(RhbDatasetDownload $download): array;
}
