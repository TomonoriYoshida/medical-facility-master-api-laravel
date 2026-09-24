<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * For 東海北陸厚生局, which bundles one .xlsx per prefecture into a single
 * ZIP (verified against the real download: 6 files, one per prefecture,
 * each a single-sheet workbook, nested inside a folder entry within the
 * zip -- e.g. "2609　コード内容別医療機関一覧表（医科）\2609（富山医科）
 * コード内容別医療機関一覧表.xlsx"). Extracts every .xlsx entry to a stable
 * location under the `local` disk and resolves each entry's prefecture
 * from its filename (which embeds the prefecture's kanji name combined
 * with the category inside parentheses, e.g. "（富山医科）" -- a different
 * position than Kanto-Shinetsu's plain suffix, but a substring match
 * against known prefecture names handles it the same way regardless of
 * surrounding folder path or parentheses).
 *
 * Deliberately bureau-specific, not a shared "ZIP of files" expander, for
 * the same reason as KantoShinetsuBundleExpander: real bureaus with a
 * superficially similar shape differ in naming/nesting details that a
 * shared implementation would paper over rather than reuse meaningfully.
 */
final class TokaiHokurikuBundleExpander implements BundleExpanderInterface
{
    /**
     * @var array<string, string>
     */
    private const array PREFECTURE_CODES_BY_NAME = [
        '富山' => '16',
        '石川' => '17',
        '岐阜' => '21',
        '静岡' => '22',
        '愛知' => '23',
        '三重' => '24',
    ];

    public function __construct(
        private readonly ZipBundleReader $zipBundleReader = new ZipBundleReader,
    ) {}

    public function expand(RhbDatasetDownload $download): array
    {
        $zipPath = Storage::disk('local')->path($download->local_path);
        $extractDir = "rhb/tokaihokuriku/extracted/{$download->id}";

        $units = [];

        foreach ($this->zipBundleReader->entries($zipPath, $download->id) as $entryName => $readContents) {
            if (! str_ends_with($entryName, '.xlsx')) {
                continue;
            }

            $prefectureCode = $this->resolvePrefectureCode($entryName, $download->id);

            // basename() only splits on "/" on Linux, but this
            // bureau's zip nests entries under a backslash-separated
            // folder path (Windows-zip origin) -- without normalizing
            // first, the folder prefix would leak into the stored
            // filename.
            $targetPath = "{$extractDir}/".basename(str_replace('\\', '/', $entryName));
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
