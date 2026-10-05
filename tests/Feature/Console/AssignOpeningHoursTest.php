<?php

namespace Tests\Feature\Console;

use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityOpeningPeriod;
use App\Models\MedicalInfoNetLocation;
use App\Models\MedicalInfoNetSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignOpeningHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rhb.scope.prefectures' => ['13']]);
    }

    public function test_a_matched_facility_gets_its_match_and_opening_periods_without_moving_updated_at(): void
    {
        $facility = $this->facility();
        $location = $this->location('丸の内クリニック', ['closures' => ['weekly' => [], 'monthly' => [['week' => 2, 'day' => 'mon']], 'holidays' => null, 'other' => null]]);
        MedicalInfoNetSchedule::factory()->create(['source_id' => $location->source_id]);

        $this->artisan('facilities:assign-opening-hours')
            ->expectsOutputToContain('1施設のうち1施設を医療情報ネットと照合し、診療時間を10件の時間帯として保存しました。')
            ->assertExitCode(0);

        $facility->refresh();
        $this->assertSame($location->source_id, $facility->medical_info_net_id);
        $this->assertSame('2026-01-01 00:00:00', $facility->updated_at->toDateTimeString());
        // The factory's 内科: reception 08:45-12:00 and 14:00-17:30 on weekdays.
        $this->assertSame(
            [['day' => 1, 'opens' => '08:45:00', 'closes' => '12:00:00', 'weeks' => 0b11101], ['day' => 1, 'opens' => '14:00:00', 'closes' => '17:30:00', 'weeks' => 0b11101]],
            MedicalFacilityOpeningPeriod::query()->where('day', 1)->orderBy('opens')->get(['day', 'opens', 'closes', 'weeks'])->toArray(),
        );
    }

    public function test_facilities_without_exactly_one_match_get_none_and_lose_a_stale_one(): void
    {
        $unmatched = $this->facility(['name' => '別のクリニック', 'medical_info_net_id' => 'stale']);
        $ambiguous = $this->facility(['name' => '同名クリニック', 'address' => '東京都千代田区丸の内１丁目５番１号']);
        $withoutMunicipality = $this->facility(['municipality_code' => null]);
        MedicalFacilityOpeningPeriod::factory()->create(['medical_facility_id' => $unmatched->id]);
        $this->location('丸の内クリニック', ['institution_type' => InstitutionType::DentalClinic]);
        $this->location('同名クリニック', ['address_key' => '千代田区丸の内1-6-1']);
        $this->location('同名クリニック', ['address_key' => '千代田区丸の内1-7-1']);

        $this->artisan('facilities:assign-opening-hours')->assertExitCode(0);

        foreach ([$unmatched, $ambiguous, $withoutMunicipality] as $facility) {
            $this->assertNull($facility->refresh()->medical_info_net_id);
        }
        $this->assertSame(0, MedicalFacilityOpeningPeriod::query()->count());
    }

    public function test_a_run_is_skipped_until_the_facilities_or_the_medical_info_net_change(): void
    {
        $this->facility();
        $this->artisan('facilities:assign-opening-hours')->assertExitCode(0);

        $this->artisan('facilities:assign-opening-hours')
            ->expectsOutputToContain('変わっていません')
            ->assertExitCode(0);

        $this->artisan('facilities:assign-opening-hours', ['--force' => true])
            ->expectsOutputToContain('1施設のうち0施設')
            ->assertExitCode(0);

        $this->location('丸の内クリニック');
        $this->artisan('facilities:assign-opening-hours')
            ->expectsOutputToContain('1施設のうち1施設')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function facility(array $attributes = []): MedicalFacility
    {
        $facility = MedicalFacility::factory()->create([
            'institution_type' => InstitutionType::Clinic,
            'prefecture_code' => '13',
            'name' => '医療法人社団　丸の内クリニック',
            'address' => '東京都千代田区丸の内１丁目９番１号　ビル２階',
            ...array_diff_key($attributes, ['municipality_code' => true, 'medical_info_net_id' => true]),
        ]);
        // Not fillable: set by facilities:assign-municipalities and this command.
        $facility->forceFill([
            'municipality_code' => array_key_exists('municipality_code', $attributes) ? $attributes['municipality_code'] : '13101',
            'medical_info_net_id' => $attributes['medical_info_net_id'] ?? null,
        ])->save();
        MedicalFacility::query()->whereKey($facility->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);

        return $facility;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function location(string $nameKey, array $attributes = []): MedicalInfoNetLocation
    {
        return MedicalInfoNetLocation::factory()->create([
            'name_key' => $nameKey,
            'address_key' => '千代田区丸の内1-9-1',
            ...$attributes,
        ]);
    }
}
