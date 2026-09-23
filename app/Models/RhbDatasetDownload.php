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
     * the most recently published row per distinct filename, not every
     * row ever recorded. Two distinct considerations this reconciles:
     *
     * - A single category can legitimately have more than one
     *   concurrently-current file (e.g. Hokkaido publishes hospital and
     *   clinic data as two separate files, both under
     *   RhbCategory::Medical) -- so there is no single "the latest" row
     *   across the whole category.
     * - Some bureaus (Hokkaido confirmed) never change a document's
     *   filename between monthly updates, so successive `rhb:download`
     *   runs accumulate multiple rows sharing the same filename but
     *   different published_on -- only the most recent one per filename
     *   is "current"; the rest are historical and must not be reprocessed.
     *
     * @return Collection<int, self>
     */
    public static function allFor(RhbBureau $bureau, RhbCategory $category): Collection
    {
        return static::query()
            ->where('bureau_code', $bureau)
            ->where('category', $category)
            ->orderByDesc('published_on')
            ->get()
            ->unique('filename')
            ->values();
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
