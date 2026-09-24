<?php

namespace Tests\Feature\Seeders;

use App\Models\KanjiVariant;
use Database\Seeders\KanjiVariantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanjiVariantSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_rerun_to_apply_csv_changes(): void
    {
        $this->seed(KanjiVariantSeeder::class);
        $seededCount = KanjiVariant::count();

        // Simulates a mapping that was later corrected in the CSV.
        KanjiVariant::where('variant_character', '髙')->update(['canonical_character' => '誤']);

        $this->seed(KanjiVariantSeeder::class);

        $this->assertSame($seededCount, KanjiVariant::count());
        $this->assertSame('高', KanjiVariant::where('variant_character', '髙')->value('canonical_character'));
    }
}
