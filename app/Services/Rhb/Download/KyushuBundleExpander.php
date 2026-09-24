<?php

namespace App\Services\Rhb\Download;

use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * For 九州厚生局, whose zip bundles all 3 categories for ONE prefecture
 * (the opposite axis from every other bureau -- see KyushuLinkResolver),
 * this picks out only the ONE entry matching $download->category, since
 * RhbDatasetDownload/ImportRhbFacilityListJob assume one category per
 * download row. Verified against the real download: 3 flat .xlsx entries
 * per zip (e.g. "r8_09_ika_fukuoka_02.xlsx"), no folder nesting, no 併設
 * subset files.
 *
 * The category slug and the romanized prefecture name appear in different
 * orders across offices (Fukuoka: "ika_fukuoka"; every other office:
 * "{prefecture}_ika"), so both are resolved by underscore-bounded
 * substring match rather than a fixed position -- bounding the category
 * slug matters because "ika" is itself a substring of "shika".
 */
final class KyushuBundleExpander implements BundleExpanderInterface
{
    /**
     * @var array<int, string>
     */
    private const array CATEGORY_SLUGS = [
        RhbCategory::Medical->value => 'ika',
        RhbCategory::Dental->value => 'shika',
        RhbCategory::Pharmacy->value => 'yakkyoku',
    ];

    /**
     * @var array<string, string>
     */
    private const array PREFECTURE_CODES_BY_NAME = [
        'fukuoka' => '40',
        'saga' => '41',
        'nagasaki' => '42',
        'kumamoto' => '43',
        'ooita' => '44',
        'miyazaki' => '45',
        'kagoshima' => '46',
        'okinawa' => '47',
    ];

    public function __construct(
        private readonly ZipBundleReader $zipBundleReader = new ZipBundleReader,
    ) {}

    public function expand(RhbDatasetDownload $download): array
    {
        $zipPath = Storage::disk('local')->path($download->local_path);
        $extractDir = "rhb/kyushu/extracted/{$download->id}";
        $categorySlug = self::CATEGORY_SLUGS[$download->category->value];

        foreach ($this->zipBundleReader->entries($zipPath, $download->id) as $entryName => $readContents) {
            if (! str_ends_with($entryName, '.xlsx') || ! str_contains($entryName, "_{$categorySlug}_")) {
                continue;
            }

            $prefectureCode = $this->resolvePrefectureCode($entryName, $download->id);

            $targetPath = "{$extractDir}/".basename($entryName);
            Storage::disk('local')->put($targetPath, $readContents());

            return [new RhbFileUnit(
                bureau: $download->bureau_code,
                category: $download->category,
                prefectureCode: $prefectureCode,
                xlsxPath: Storage::disk('local')->path($targetPath),
            )];
        }

        throw new RuntimeException("Unable to find a \"{$categorySlug}\" entry in zip \"{$zipPath}\" (download #{$download->id}).");
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
