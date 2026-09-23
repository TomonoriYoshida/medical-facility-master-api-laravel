<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * For bureaus that publish one .xlsx per (bureau, category, prefecture)
 * download with no bundling (Hokkaido: a single prefecture, no zip, no
 * multi-sheet file) -- a pass-through wrapping the download's own local
 * file as the one unit.
 */
final class SingleFileBundleExpander implements BundleExpanderInterface
{
    public function expand(RhbDatasetDownload $download): array
    {
        $prefectureCode = $download->prefecture_codes[0]
            ?? throw new RuntimeException("Download #{$download->id} has no prefecture_codes.");

        return [
            new RhbFileUnit(
                bureau: $download->bureau_code,
                category: $download->category,
                prefectureCode: $prefectureCode,
                xlsxPath: Storage::disk('local')->path($download->local_path),
            ),
        ];
    }
}
