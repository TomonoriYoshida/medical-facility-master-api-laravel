<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * For 中国四国厚生局, which bundles one .xlsx per prefecture into a single
 * ZIP (verified against the real download: 5 files, one per prefecture,
 * each a single-sheet workbook, flat inside the zip with no folder
 * nesting and no 併設 (co-located) subset files). Extracts every .xlsx
 * entry to a stable location under the `local` disk and resolves each
 * entry's prefecture from its filename (which embeds the prefecture's
 * kanji name, e.g. "医科_31_鳥取_コード内容別医療機関一覧表.xlsx").
 *
 * Deliberately bureau-specific, not a shared "ZIP of files" expander, for
 * the same reason as the other ZIP-of-files expanders in this namespace.
 */
final class ChugokuShikokuBundleExpander implements BundleExpanderInterface
{
    /**
     * @var array<string, string>
     */
    private const array PREFECTURE_CODES_BY_NAME = [
        '鳥取' => '31',
        '島根' => '32',
        '岡山' => '33',
        '広島' => '34',
        '山口' => '35',
    ];

    public function __construct(
        private readonly ZipBundleReader $zipBundleReader = new ZipBundleReader,
    ) {}

    public function expand(RhbDatasetDownload $download): array
    {
        $zipPath = Storage::disk('local')->path($download->local_path);
        $extractDir = "rhb/chugokushikoku/extracted/{$download->id}";

        $units = [];

        foreach ($this->zipBundleReader->entries($zipPath, $download->id) as $entryName => $readContents) {
            if (! str_ends_with($entryName, '.xlsx')) {
                continue;
            }

            $prefectureCode = $this->resolvePrefectureCode($entryName, $download->id);

            $targetPath = "{$extractDir}/".basename($entryName);
            Storage::disk('local')->put($targetPath, $readContents());

            $units[] = new RhbFileUnit(
                bureau: $download->bureau_code,
                category: $download->category,
                prefectureCode: $prefectureCode,
                xlsxPath: Storage::disk('local')->path($targetPath),
            );
        }

        return $units;
    }

    private function resolvePrefectureCode(string $entryName, int $downloadId): string
    {
        foreach (self::PREFECTURE_CODES_BY_NAME as $name => $code) {
            if (str_contains($entryName, $name)) {
                return $code;
            }
        }

        throw new RuntimeException("Unable to resolve a prefecture from zip entry \"{$entryName}\" (download #{$downloadId}).");
    }
}
