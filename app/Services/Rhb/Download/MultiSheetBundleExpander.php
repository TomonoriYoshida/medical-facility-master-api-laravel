<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Import\RhbXlsxReader;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * For bureaus that bundle multiple prefectures into one workbook, one
 * sheet per prefecture (Tohoku confirmed: sheet names are the prefecture
 * name strings, e.g. "青森県"). Expands each sheet into its own
 * RhbFileUnit pointing at the same underlying .xlsx path with a distinct
 * sheet name, which App\Services\Rhb\Import\RhbXlsxReader already supports
 * natively (it was designed with multi-sheet bureaus in mind from Phase A).
 *
 * The sheet-name -> prefecture-code mapping only covers prefectures
 * actually seen so far; an unrecognized sheet name throws rather than
 * being silently skipped, since a bureau silently dropping a whole
 * prefecture's worth of facilities would be a much worse failure mode
 * than a loud one.
 */
final class MultiSheetBundleExpander implements BundleExpanderInterface
{
    /**
     * @var array<string, string>
     */
    private const array PREFECTURE_CODES_BY_SHEET_NAME = [
        '青森県' => '02',
        '岩手県' => '03',
        '宮城県' => '04',
        '秋田県' => '05',
        '山形県' => '06',
        '福島県' => '07',
    ];

    public function expand(RhbDatasetDownload $download): array
    {
        $path = Storage::disk('local')->path($download->local_path);
        $reader = new RhbXlsxReader($path);

        $units = [];

        foreach ($reader->sheetNames() as $sheetName) {
            $prefectureCode = self::PREFECTURE_CODES_BY_SHEET_NAME[$sheetName]
                ?? throw new RuntimeException("Unknown prefecture sheet name \"{$sheetName}\" in download #{$download->id}.");

            $units[] = new RhbFileUnit(
                bureau: $download->bureau_code,
                category: $download->category,
                prefectureCode: $prefectureCode,
                xlsxPath: $path,
                sheetName: $sheetName,
            );
        }

        return $units;
    }
}
