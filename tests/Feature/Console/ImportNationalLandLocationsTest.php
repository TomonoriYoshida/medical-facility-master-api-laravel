<?php

namespace Tests\Feature\Console;

use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use App\Models\Municipality;
use App\Models\NationalLandMedicalLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportNationalLandLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['rhb.scope.prefectures' => ['13']]);
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);
    }

    public function test_it_replaces_the_positions_with_those_of_the_prefectures_in_scope(): void
    {
        $previous = NationalLandMedicalLocation::factory()->create();
        $this->fakeNationalLand([
            $this->feature(2, '(医)丸の内クリニック', '千代田区丸の内１丁目９番地の１', 139.7671, 35.6812),
            $this->feature(1, '丸の内病院', '千代田区丸の内２丁目１番１号', 139.7640, 35.6790),
            // Not a municipality in the prefecture.
            $this->feature(2, '所在不明クリニック', '丸の内１丁目１番１号', 139.76, 35.68),
            [...$this->feature(2, '複数地点クリニック', '千代田区丸の内３丁目１番１号', 0, 0), 'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[139.76, 35.68]]]],
        ]);

        $this->artisan('national-land:import')
            ->expectsOutputToContain('2件を取り込みました（市区町村を特定できないなどで2件を除く）')
            ->assertExitCode(0);

        $this->assertModelMissing($previous);
        $clinic = NationalLandMedicalLocation::query()->where('institution_type', InstitutionType::Clinic)->sole();
        $this->assertSame('13101', $clinic->municipality_code);
        $this->assertSame('丸の内クリニック', $clinic->name_key);
        // Without the municipality, which the data spells its own way at times.
        $this->assertSame('丸の内1-9-1', $clinic->address_key);
        $this->assertSame('35.681200', $clinic->latitude);
        $this->assertSame('139.767100', $clinic->longitude);
        $this->assertSame(1, NationalLandMedicalLocation::query()->where('institution_type', InstitutionType::Hospital)->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/P04-20_13_GML.zip'));
    }

    public function test_facilities_that_could_use_the_new_positions_are_marked_for_geocoding(): void
    {
        $this->fakeNationalLand([]);
        $town = MedicalFacility::factory()->create(['geocode_level' => GeocodeLevel::Town, 'latitude' => 35.68, 'longitude' => 139.76]);
        $nationalLand = MedicalFacility::factory()->create(['geocode_level' => GeocodeLevel::NationalLand, 'latitude' => 35.68, 'longitude' => 139.76]);
        $medicalInfoNet = MedicalFacility::factory()->create(['geocode_level' => GeocodeLevel::MedicalInfoNet, 'latitude' => 35.68, 'longitude' => 139.76]);
        $unlocated = MedicalFacility::factory()->create();
        MedicalFacility::query()->toBase()->update(['geocoded_address' => 'old', 'updated_at' => '2026-01-01 00:00:00']);

        $this->artisan('national-land:import')->assertExitCode(0);

        $this->assertNull($town->refresh()->geocoded_address);
        $this->assertNull($nationalLand->refresh()->geocoded_address);
        $this->assertNull($unlocated->refresh()->geocoded_address);
        $this->assertSame('old', $medicalInfoNet->refresh()->geocoded_address);
        $this->assertSame('2026-01-01 00:00:00', $town->updated_at->toDateTimeString());
    }

    /**
     * @param  list<array<string, mixed>>  $features
     */
    private function fakeNationalLand(array $features): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ksj');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        // Named as in the real zips, which also hold the Shapefile and GML.
        $zip->addFromString('P04-20_13_GML\\P04-20_13.dbf', '');
        $zip->addFromString('P04-20_13_GML\\P04-20_13.geojson', (string) json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE));
        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        Http::fake(['nlftp.mlit.go.jp/*' => Http::response($contents)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(int $type, string $name, string $address, float $longitude, float $latitude): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['P04_001' => $type, 'P04_002' => $name, 'P04_003' => $address],
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
        ];
    }
}
