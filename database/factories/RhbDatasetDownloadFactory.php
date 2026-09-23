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
        $dateString = fake()->dateTimeBetween('-1 year', 'now')->format('Ymd');
        $filename = "code_ichiran_hospital_{$dateString}.xlsx";

        return [
            'bureau_code' => RhbBureau::Hokkaido,
            'category' => RhbCategory::Medical,
            'prefecture_codes' => ['01'],
            'filename' => $filename,
            'source_url' => "https://kouseikyoku.mhlw.go.jp/hokkaido/{$filename}",
            'local_path' => "rhb/hokkaido/medical/{$filename}",
            'published_on' => $dateString,
            'downloaded_at' => now(),
        ];
    }
}
