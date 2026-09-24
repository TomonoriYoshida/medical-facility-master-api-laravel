<?php

namespace Tests\Feature\Console;

use App\Models\KanjiVariant;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Tests\TestCase;

class RenormalizeMedicalFacilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_a_newly_added_kanji_variant_to_existing_facilities(): void
    {
        $facility = MedicalFacility::factory()->create([
            'name' => '髙橋病院',
            'address' => '髙松市番町1丁目',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->assertSame('髙橋病院', $facility->name_normalized);

        KanjiVariant::create([
            'variant_character' => '髙',
            'canonical_character' => '高',
            'source' => 'manual',
        ]);
        Once::flush();

        $this->artisan('facilities:renormalize')
            ->expectsOutputToContain('1件')
            ->assertExitCode(0);

        $facility->refresh();
        $this->assertSame('高橋病院', $facility->name_normalized);
        $this->assertSame('高松市番町1丁目', $facility->address_normalized);
        $this->assertSame('2026-01-01 00:00:00', $facility->updated_at->toDateTimeString());
    }

    public function test_already_up_to_date_facilities_are_left_untouched(): void
    {
        MedicalFacility::factory()->create(['name' => '山田病院']);

        $this->artisan('facilities:renormalize')
            ->expectsOutputToContain('0件')
            ->assertExitCode(0);
    }

    public function test_it_signals_queue_workers_to_restart(): void
    {
        $this->artisan('facilities:renormalize')->assertExitCode(0);

        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
    }
}
