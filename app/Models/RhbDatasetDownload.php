<?php

namespace App\Models;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use Database\Factories\RhbDatasetDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
     * Get the most recently published download recorded for a bureau +
     * category pair.
     */
    public static function latestFor(RhbBureau $bureau, RhbCategory $category): ?self
    {
        return static::query()
            ->where('bureau_code', $bureau)
            ->where('category', $category)
            ->orderByDesc('published_on')
            ->first();
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
