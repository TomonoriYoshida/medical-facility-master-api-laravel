<?php

namespace Tests\Feature\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Enums\Prefecture;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use App\Services\Address\FacilityMatchingKeys;
use App\Services\Geocoding\GeocodeResult;
use App\Services\Geocoding\MedicalInfoNetLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalInfoNetLocatorTest extends TestCase
{
    use RefreshDatabase;

    private const array FACILITY = [
        'institution_type' => 2,
        'municipality_code' => '13101',
        'name' => '医療法人　丸の内クリニック',
        'address' => '千代田区丸の内一丁目９番１号　ビル２階',
    ];

    public function test_a_town_level_location_is_replaced_by_a_nearby_medical_info_net_one(): void
    {
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671);

        $results = $this->refine([1 => self::FACILITY], [1 => $this->town(35.6800, 139.7650)]);

        $this->assertEquals(new GeocodeResult(GeocodeLevel::MedicalInfoNet, 35.6812, 139.7671), $results[1]);
    }

    public function test_a_medical_info_net_location_far_from_the_town_is_not_used(): void
    {
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.7300, 139.7671);
        $town = $this->town(35.6800, 139.7650);

        $this->assertSame($town, $this->refine([1 => self::FACILITY], [1 => $town])[1]);
    }

    public function test_a_more_precise_location_is_kept(): void
    {
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671);
        $residence = new GeocodeResult(GeocodeLevel::Residence, 35.6811, 139.7670);

        $this->assertSame($residence, $this->refine([1 => self::FACILITY], [1 => $residence])[1]);
    }

    public function test_a_facility_without_location_is_checked_against_its_municipality(): void
    {
        MedicalFacility::factory()->count(5)->create([
            'prefecture_code' => '13',
            'latitude' => 35.68,
            'longitude' => 139.76,
            // 地番 only, as in towns without 住居表示.
            'geocode_level' => GeocodeLevel::Parcel,
        ])->each(fn (MedicalFacility $facility) => MedicalFacility::query()->whereKey($facility->id)->toBase()->update(['municipality_code' => '13101']));
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671);
        $this->medicalInfoNet('離島クリニック', '東京都千代田区丸の内1-9-9', 33.1, 139.79);

        $results = $this->refine(
            [1 => self::FACILITY, 2 => [...self::FACILITY, 'name' => '離島クリニック', 'address' => '千代田区丸の内1-9-9']],
            [1 => null, 2 => null],
        );

        $this->assertEquals(new GeocodeResult(GeocodeLevel::MedicalInfoNet, 35.6812, 139.7671), $results[1]);
        $this->assertNull($results[2]);
    }

    public function test_an_ambiguous_name_falls_back_to_the_address(): void
    {
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671);
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内2-1-1', 35.6790, 139.7640);

        $results = $this->refine([1 => self::FACILITY], [1 => $this->town(35.6800, 139.7650)]);

        $this->assertEquals(new GeocodeResult(GeocodeLevel::MedicalInfoNet, 35.6812, 139.7671), $results[1]);
    }

    public function test_a_namesake_listed_without_coordinates_is_not_mistaken_for_another(): void
    {
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671);
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内3-3-3', null, null);
        $town = $this->town(35.6800, 139.7650);

        $this->assertSame($town, $this->refine([1 => [...self::FACILITY, 'address' => '千代田区丸の内3-3-3']], [1 => $town])[1]);
    }

    public function test_the_same_address_alone_needs_a_name_containing_the_other(): void
    {
        $this->medicalInfoNet('ひかり内科クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671);
        $town = $this->town(35.6800, 139.7650);

        // Same address and the same ending ("クリニック"), but another facility.
        $this->assertSame($town, $this->refine([1 => self::FACILITY], [1 => $town])[1]);
    }

    public function test_another_type_or_municipality_is_not_matched(): void
    {
        $this->medicalInfoNet('丸の内クリニック', '東京都千代田区丸の内1-9-1', 35.6812, 139.7671, InstitutionType::DentalClinic);
        $this->medicalInfoNet('丸の内クリニック', '東京都中央区丸の内1-9-1', 35.6812, 139.7671, municipalityCode: '13102');
        $town = $this->town(35.6800, 139.7650);

        $this->assertSame($town, $this->refine([1 => self::FACILITY], [1 => $town])[1]);
    }

    /**
     * @param  array<int, array{institution_type: int, municipality_code: ?string, name: string, address: string}>  $facilities
     * @param  array<int, GeocodeResult|null>  $results
     * @return array<int, GeocodeResult|null>
     */
    private function refine(array $facilities, array $results): array
    {
        return app(MedicalInfoNetLocator::class)->refine(Prefecture::Tokyo, $facilities, $results);
    }

    private function town(float $latitude, float $longitude): GeocodeResult
    {
        return new GeocodeResult(GeocodeLevel::Town, $latitude, $longitude);
    }

    private function medicalInfoNet(string $name, string $address, ?float $latitude, ?float $longitude, InstitutionType $type = InstitutionType::Clinic, string $municipalityCode = '13101'): void
    {
        $keys = app(FacilityMatchingKeys::class);

        MedicalInfoNetLocation::factory()->create([
            'institution_type' => $type,
            'municipality_code' => $municipalityCode,
            'name_key' => $keys->name($name),
            'address_key' => $keys->address($address),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
