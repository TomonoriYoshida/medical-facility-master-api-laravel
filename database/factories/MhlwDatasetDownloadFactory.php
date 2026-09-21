<?php

namespace Database\Factories;

use App\Models\MhlwDatasetDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MhlwDatasetDownload>
 */
class MhlwDatasetDownloadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $datasetKey = fake()->randomElement(array_keys(config('mhlw.datasets')));
        $slug = config("mhlw.datasets.{$datasetKey}.slug");
        $dateString = fake()->dateTimeBetween('-1 year', 'now')->format('Ymd');
        $filename = "{$slug}_{$dateString}.zip";

        return [
            'dataset_key' => $datasetKey,
            'filename' => $filename,
            'published_on' => $dateString,
            'source_url' => config('mhlw.base_url')."/content/11121000/{$filename}",
            'local_path' => "mhlw/{$datasetKey}/{$filename}",
            'downloaded_at' => now(),
        ];
    }
}
