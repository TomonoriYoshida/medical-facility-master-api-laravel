<?php

namespace Tests\Feature\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Enums\Prefecture;
use App\Models\MedicalFacility;
use App\Models\Municipality;
use App\Models\NationalLandMedicalLocation;
use App\Services\Address\FacilityMatchingKeys;
use App\Services\Geocoding\GeocodeResult;
use App\Services\Geocoding\NationalLandLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NationalLandLocatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);
    }

    private const array FACILITY = [
        'institution_type' => 2,
        'municipality_code' => '13101',
        'name' => '医療法人　丸の内クリニック',
        'address' => '千代田区丸の内一丁目９番１号　ビル２階',
    ];

    public function test_a_town_level_location_is_replaced_by_the_position_at_the_same_address(): void
    {
        $this->nationalLand('(医)丸の内クリニック', '千代田区丸の内1丁目9番地の1', 35.6812, 139.7671);

        $results = $this->refine([1 => self::FACILITY], [1 => $this->town(35.6800, 139.7650)]);

        $this->assertEquals(new GeocodeResult(GeocodeLevel::NationalLand, 35.6812, 139.7671), $results[1]);
    }

    public function test_a_position_at_another_address_is_not_used(): void
    {
        // The facility has moved since the data was made.
        $this->nationalLand('丸の内クリニック', '千代田区丸の内2-1-1', 35.6790, 139.7640);
        $town = $this->town(35.6800, 139.7650);

        $this->assertSame($town, $this->refine([1 => self::FACILITY], [1 => $town])[1]);
    }

    public function test_a_position_within_ten_kilometers_of_the_town_is_used_but_not_further(): void
    {
        $this->nationalLand('丸の内クリニック', '千代田区丸の内1-9-1', 35.7500, 139.7650);
        $this->nationalLand('大手町クリニック', '千代田区大手町1-1-1', 35.7800, 139.7650);
        $town = $this->town(35.6800, 139.7650);

        $results = $this->refine(
            [1 => self::FACILITY, 2 => [...self::FACILITY, 'name' => '大手町クリニック', 'address' => '千代田区大手町1-1-1']],
            [1 => $town, 2 => $town],
        );

        // About 7.8km and 11.1km away.
        $this->assertEquals(new GeocodeResult(GeocodeLevel::NationalLand, 35.75, 139.765), $results[1]);
        $this->assertSame($town, $results[2]);
    }

    public function test_a_more_precise_or_medical_info_net_location_is_kept(): void
    {
        $this->nationalLand('丸の内クリニック', '千代田区丸の内1-9-1', 35.6812, 139.7671);
        $residence = new GeocodeResult(GeocodeLevel::Residence, 35.6811, 139.7670);
        $medicalInfoNet = new GeocodeResult(GeocodeLevel::MedicalInfoNet, 35.6813, 139.7672);

        $results = $this->refine([1 => self::FACILITY, 2 => self::FACILITY], [1 => $residence, 2 => $medicalInfoNet]);

        $this->assertSame([1 => $residence, 2 => $medicalInfoNet], $results);
    }

    public function test_a_facility_without_location_is_checked_against_its_municipality(): void
    {
        MedicalFacility::factory()->count(5)->create([
            'prefecture_code' => '13',
            'latitude' => 35.68,
            'longitude' => 139.76,
            'geocode_level' => GeocodeLevel::Parcel,
        ])->each(fn (MedicalFacility $facility) => MedicalFacility::query()->whereKey($facility->id)->toBase()->update(['municipality_code' => '13101']));
        $this->nationalLand('丸の内クリニック', '千代田区丸の内1-9-1', 35.6812, 139.7671);
        $this->nationalLand('離島クリニック', '千代田区丸の内1-9-9', 33.1, 139.79);

        $results = $this->refine(
            [1 => self::FACILITY, 2 => [...self::FACILITY, 'name' => '離島クリニック', 'address' => '千代田区丸の内1-9-9']],
            [1 => null, 2 => null],
        );

        $this->assertEquals(new GeocodeResult(GeocodeLevel::NationalLand, 35.6812, 139.7671), $results[1]);
        $this->assertNull($results[2]);
    }

    public function test_another_type_or_municipality_is_not_matched(): void
    {
        $this->nationalLand('丸の内クリニック', '千代田区丸の内1-9-1', 35.6812, 139.7671, InstitutionType::DentalClinic);
        $this->nationalLand('丸の内クリニック', '中央区丸の内1-9-1', 35.6812, 139.7671, municipalityCode: '13102');
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
        return app(NationalLandLocator::class)->refine(Prefecture::Tokyo, $facilities, $results);
    }

    private function town(float $latitude, float $longitude): GeocodeResult
    {
        return new GeocodeResult(GeocodeLevel::Town, $latitude, $longitude);
    }

    private function nationalLand(string $name, string $address, float $latitude, float $longitude, InstitutionType $type = InstitutionType::Clinic, string $municipalityCode = '13101'): void
    {
        $keys = app(FacilityMatchingKeys::class);
        // As the import makes it: from after the municipality.
        $address = preg_replace('/^(千代田区|中央区)/u', '', $address);

        NationalLandMedicalLocation::factory()->create([
            'institution_type' => $type,
            'municipality_code' => $municipalityCode,
            'name_key' => $keys->name($name),
            'address_key' => $keys->address($address),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
