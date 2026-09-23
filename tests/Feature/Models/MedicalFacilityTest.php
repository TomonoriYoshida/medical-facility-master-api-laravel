<?php

namespace Tests\Feature\Models;

use App\Models\KanjiVariant;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalFacilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_facility_populates_the_normalized_name_column(): void
    {
        KanjiVariant::create([
            'variant_character' => '髙',
            'canonical_character' => '高',
            'source' => 'manual',
        ]);

        $facility = MedicalFacility::factory()->create(['name' => '髙橋病院']);

        $this->assertSame('高橋病院', $facility->name_normalized);
    }

    public function test_updating_an_unrelated_column_does_not_recompute_name_normalized(): void
    {
        $facility = MedicalFacility::factory()->create(['name' => '山田病院']);

        $facility->name_normalized = '書き換え済み';
        $facility->save();

        $facility->update(['phone_number' => '011-000-0000']);

        $this->assertSame('書き換え済み', $facility->fresh()->name_normalized);
    }
}
