<?php

namespace Tests\Feature\Services\Text;

use App\Models\KanjiVariant;
use App\Services\Text\ItaijiNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItaijiNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_full_width_characters_via_nfkc_without_any_itaiji_involved(): void
    {
        $normalizer = new ItaijiNormalizer;

        $this->assertSame('ABC123', $normalizer->normalize('ＡＢＣ１２３'));
        $this->assertSame('a b', $normalizer->normalize("a\u{3000}b"));
    }

    public function test_it_substitutes_known_itaiji_using_seeded_mappings(): void
    {
        KanjiVariant::create([
            'variant_character' => '髙',
            'canonical_character' => '高',
            'source' => 'manual',
        ]);
        KanjiVariant::create([
            'variant_character' => '德',
            'canonical_character' => '徳',
            'source' => 'kJapaneseNewVariant',
        ]);

        $normalizer = new ItaijiNormalizer;

        $this->assertSame('高橋', $normalizer->normalize('髙橋'));
        $this->assertSame('徳田', $normalizer->normalize('德田'));
    }

    public function test_it_combines_nfkc_and_itaiji_normalization_in_one_pass(): void
    {
        KanjiVariant::create([
            'variant_character' => '澤',
            'canonical_character' => '沢',
            'source' => 'kJapaneseNewVariant',
        ]);

        $normalizer = new ItaijiNormalizer;

        $this->assertSame('沢田クリニック ABC', $normalizer->normalize('澤田クリニック　ＡＢＣ'));
    }

    public function test_characters_with_no_mapping_pass_through_unchanged(): void
    {
        KanjiVariant::create([
            'variant_character' => '髙',
            'canonical_character' => '高',
            'source' => 'manual',
        ]);

        $normalizer = new ItaijiNormalizer;

        $this->assertSame('山田太郎', $normalizer->normalize('山田太郎'));
    }
}
