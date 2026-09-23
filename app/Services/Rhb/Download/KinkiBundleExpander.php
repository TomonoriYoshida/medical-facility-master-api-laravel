<?php

namespace App\Services\Rhb\Download;

use App\Models\RhbDatasetDownload;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * For 近畿厚生局, which bundles one .xlsx per prefecture into a single ZIP
 * (verified against the real download: 7 prefectures, each a single-sheet
 * workbook), but unlike Kanto-Shinetsu/Tokai-Hokuriku, 併設 (co-located)
 * subset files are mixed into the SAME zip rather than published as
 * separate download links (e.g. "..._hyogo_ika.xlsx" alongside
 * "..._hyogo_ikaheisetu.xlsx" in the same archive) -- these must be
 * filtered out here instead of at the LinkResolver stage. Note the
 * romanization drops the "s": "heisetu", not "heisetsu".
 *
 * Extracts every wanted .xlsx entry to a stable location under the
 * `local` disk and resolves each entry's prefecture from its filename
 * (which embeds the prefecture's romanized name, e.g.
 * "2026.9_kikanzentai_hyogo_ika.xlsx"). Deliberately bureau-specific for
 * the same reason as the other ZIP-of-files expanders: real bureaus with
 * a superficially similar shape differ in naming/bundling details that a
 * shared implementation would paper over rather than reuse meaningfully.
 */
final class KinkiBundleExpander implements BundleExpanderInterface
{
    private const string HEISETSU_MARKER = 'heisetu';

    /**
     * @var array<string, string>
     */
    private const array PREFECTURE_CODES_BY_NAME = [
        'fukui' => '18',
        'shiga' => '25',
        'kyoto' => '26',
        'osaka' => '27',
        'hyogo' => '28',
        'nara' => '29',
        'wakayama' => '30',
    ];

    public function expand(RhbDatasetDownload $download): array
    {
        $zipPath = Storage::disk('local')->path($download->local_path);
        $extractDir = "rhb/kinki/extracted/{$download->id}";

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open zip \"{$zipPath}\" (download #{$download->id}).");
        }

        $units = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                if (! str_ends_with($entryName, '.xlsx') || str_contains($entryName, self::HEISETSU_MARKER)) {
                    continue;
                }

                $prefectureCode = $this->resolvePrefectureCode($entryName, $download->id);

                $targetPath = "{$extractDir}/".basename($entryName);
                Storage::disk('local')->put($targetPath, $zip->getFromIndex($i));

                $units[] = new RhbFileUnit(
                    bureau: $download->bureau_code,
                    category: $download->category,
                    prefectureCode: $prefectureCode,
                    xlsxPath: Storage::disk('local')->path($targetPath),
                );
            }
        } finally {
            $zip->close();
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
