<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * For 関東信越厚生局, which bundles one .xlsx per prefecture into a single
 * ZIP (verified against the real download: 10 files, one per prefecture,
 * each a single-sheet workbook -- unlike Tohoku's one-workbook-many-sheets
 * shape). Extracts every .xlsx entry to a stable location under the
 * `local` disk and resolves each entry's prefecture from its filename
 * (which embeds the prefecture's kanji name with no suffix, e.g.
 * "111コード内容別一覧表（医科）埼玉r0809.xlsx").
 *
 * This is deliberately bureau-specific, not a shared "ZIP of files"
 * expander: other bureaus with a superficially similar ZIP-of-files shape
 * (confirmed for Kinki) use romanized prefecture names instead of kanji,
 * and bundle the 併設 (co-located) variant files inside the same zip
 * rather than as separate download links -- a shared implementation would
 * paper over real differences rather than reuse anything meaningful.
 */
final class KantoShinetsuBundleExpander implements BundleExpanderInterface
{
    /**
     * @var array<string, string>
     */
    private const array PREFECTURE_CODES_BY_NAME = [
        '茨城' => '08',
        '栃木' => '09',
        '群馬' => '10',
        '埼玉' => '11',
        '千葉' => '12',
        '東京' => '13',
        '神奈川' => '14',
        '新潟' => '15',
        '山梨' => '19',
        '長野' => '20',
    ];

    public function __construct(
        private readonly ZipBundleReader $zipBundleReader = new ZipBundleReader,
    ) {}

    public function expand(RhbDatasetDownload $download): array
    {
        $zipPath = Storage::disk('local')->path($download->local_path);
        $extractDir = "rhb/kantoshinetsu/extracted/{$download->id}";

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
