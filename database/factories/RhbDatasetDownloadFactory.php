<?php

namespace Database\Factories;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RhbDatasetDownload>
 */
class RhbDatasetDownloadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A different day for each download in a test: (bureau, category,
        // filename, published_on) is unique, and the filename carries the day.
        $publishedOn = now()->subDays(fake()->unique()->numberBetween(0, 364));
        $filename = "code_ichiran_hospital_{$publishedOn->format('Ymd')}.xlsx";

        return [
            'bureau_code' => RhbBureau::Hokkaido,
            'category' => RhbCategory::Medical,
            'prefecture_codes' => ['01'],
            'filename' => $filename,
            'source_url' => "https://kouseikyoku.mhlw.go.jp/hokkaido/{$filename}",
            'local_path' => "rhb/hokkaido/medical/{$filename}",
            // Y-m-d: the date cast reads a bare "20260912" as a Unix timestamp.
            'published_on' => $publishedOn->toDateString(),
            'downloaded_at' => now(),
        ];
    }
}
