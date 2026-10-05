<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use App\Models\MedicalInfoNetSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalFacilityOpeningHoursControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_matching_facilitys_hours_and_days_off(): void
    {
        $location = $this->location([
            'closures' => ['other' => "年末年始\nお盆", 'holidays' => true, 'monthly' => [['day' => 'sat', 'week' => 2]], 'weekly' => ['sun']],
        ]);
        $schedule = MedicalInfoNetSchedule::factory()->create(['source_id' => $location->source_id]);
        $facility = $this->facility($location->source_id);

        $response = $this->getJson("/api/v1/medical-facilities/{$facility->id}/opening-hours");

        $response->assertOk();
        $response->assertExactJson([
            'data' => [
                'published_on' => '2026-06-01',
                'schedules' => $schedule->schedules,
                'closures' => ['weekly' => ['sun'], 'monthly' => [['week' => 2, 'day' => 'sat']], 'holidays' => true, 'other' => "年末年始\nお盆"],
            ],
            'meta' => $response->json('meta'),
        ]);
        // In a fixed key order, though MySQL reorders stored JSON objects.
        $this->assertSame(['weekly', 'monthly', 'holidays', 'other'], array_keys($response->json('data.closures')));
        $this->assertSame(['day', 'opens', 'closes', 'reception_opens', 'reception_closes'], array_keys($response->json('data.schedules.0.slots.0.days.0')));
        $response->assertJsonPath('meta.attribution.medical_info_net_source.name', '厚生労働省「医療情報ネット」のオープンデータ（所在地座標・診療時間・休診日）を加工して作成');
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_a_match_without_hours_or_days_off_has_them_empty(): void
    {
        $facility = $this->facility($this->location()->source_id);

        $this->getJson("/api/v1/medical-facilities/{$facility->id}/opening-hours")
            ->assertOk()
            ->assertJsonPath('data.schedules', [])
            ->assertJsonPath('data.closures', null);
    }

    public function test_data_is_null_for_a_facility_without_a_match(): void
    {
        // A namesake exists, but facilities:assign-opening-hours found no single match.
        $this->location(['name_key' => '丸の内クリニック']);
        $facility = $this->facility(null);

        $this->getJson("/api/v1/medical-facilities/{$facility->id}/opening-hours")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_returns_404_for_an_unknown_facility(): void
    {
        $this->getJson('/api/v1/medical-facilities/999999/opening-hours')->assertNotFound();
    }

    /**
     * A facility matched (by facilities:assign-opening-hours) with the given 医療情報ネット ID.
     */
    private function facility(?string $medicalInfoNetId): MedicalFacility
    {
        $facility = MedicalFacility::factory()->create(['institution_type' => InstitutionType::Clinic]);
        $facility->forceFill(['medical_info_net_id' => $medicalInfoNetId])->save();

        return $facility;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function location(array $attributes = []): MedicalInfoNetLocation
    {
        return MedicalInfoNetLocation::factory()->create($attributes);
    }
}
