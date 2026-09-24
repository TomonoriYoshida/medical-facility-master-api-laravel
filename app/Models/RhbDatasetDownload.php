<?php

namespace App\Models;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use Database\Factories\RhbDatasetDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'bureau_code',
    'category',
    'prefecture_codes',
    'filename',
    'source_url',
    'local_path',
    'published_on',
    'downloaded_at',
])]
class RhbDatasetDownload extends Model
{
    /** @use HasFactory<RhbDatasetDownloadFactory> */
    use HasFactory;

    /**
     * Get the current download(s) recorded for a bureau + category pair --
     * every row sharing the most recent published_on, not every row ever
     * recorded. Two distinct considerations this reconciles:
     *
     * - A single category can legitimately have more than one
     *   concurrently-current file (e.g. Hokkaido publishes hospital and
     *   clinic data as two separate files, both under
     *   RhbCategory::Medical; Kyushu publishes one zip per prefecture) --
     *   so there is no single "the latest" row across the whole category.
     *   Every BureauLinkResolver stamps all links resolved from one index
     *   page with that page's single "current as of" date, so one
     *   publication's files always share a published_on.
     * - Filenames cannot identify "the same file across months": some
     *   bureaus (Hokkaido confirmed) never change them, while others embed
     *   the month (e.g. Kanto-Shinetsu's "shitei_ika_r{YYMM}.zip"), where
     *   grouping by filename would treat every past month as current and
     *   re-import it over the latest data.
     *
     * @return Collection<int, self>
     */
    public static function allFor(RhbBureau $bureau, RhbCategory $category): Collection
    {
        $latestPublishedOn = static::query()
            ->where('bureau_code', $bureau)
            ->where('category', $category)
            ->max('published_on');

        if ($latestPublishedOn === null) {
            return new Collection;
        }

        return static::query()
            ->where('bureau_code', $bureau)
            ->where('category', $category)
            ->where('published_on', $latestPublishedOn)
            ->get();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bureau_code' => RhbBureau::class,
            'category' => RhbCategory::class,
            'prefecture_codes' => 'array',
            'published_on' => 'date',
            'downloaded_at' => 'datetime',
        ];
    }
}
