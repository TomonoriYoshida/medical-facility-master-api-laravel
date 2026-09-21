<?php

namespace App\Models;

use Database\Factories\MhlwDatasetDownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'dataset_key',
    'filename',
    'published_on',
    'source_url',
    'local_path',
    'downloaded_at',
])]
class MhlwDatasetDownload extends Model
{
    /** @use HasFactory<MhlwDatasetDownloadFactory> */
    use HasFactory;

    /**
     * Get the most recently published download recorded for a dataset key.
     */
    public static function latestFor(string $datasetKey): ?self
    {
        return static::query()
            ->where('dataset_key', $datasetKey)
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
            'published_on' => 'date',
            'downloaded_at' => 'datetime',
        ];
    }
}
