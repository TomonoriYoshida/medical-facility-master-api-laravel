<?php

namespace Tests\Feature\Console;

use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportMedicalInfoNetLocationsTest extends TestCase
{
    use RefreshDatabase;

    private const array HEADER = ['ID', '正式名称', '正式名称（フリガナ）', '都道府県コード', '市区町村コード', '所在地', '所在地座標（緯度）', '所在地座標（経度）'];

    private const array PHARMACY_HEADER = ['ID', '名称', 'フリガナ', '都道府県コード', '市区町村コード', '所在地', '所在地座標（緯度）', '所在地座標（経度）'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['rhb.scope.prefectures' => ['13']]);
    }

    public function test_it_imports_the_latest_publication_within_the_scope(): void
    {
        $this->fakeMedicalInfoNet();

        $this->artisan('medical-info-net:import')
            ->expectsOutputToContain('2026-06-01時点の版から3件（うち座標あり2件）')
            ->assertExitCode(0);

        $clinic = MedicalInfoNetLocation::query()->where('name_key', '丸の内クリニック')->sole();
        $this->assertSame('13101', $clinic->municipality_code);
        $this->assertSame('丸の内クリニック', $clinic->name_key);
        $this->assertSame('千代田区丸の内1-9-1', $clinic->address_key);
        $this->assertSame('35.681200', $clinic->latitude);
        $this->assertSame('2026-06-01', $clinic->published_on->toDateString());
        $this->assertSame(1, MedicalInfoNetLocation::query()->where('institution_type', InstitutionType::Pharmacy)->count());
        // Kept without coordinates, so that matching by name still sees it.
        $this->assertNull(MedicalInfoNetLocation::query()->where('name_key', '座標なしクリニック')->sole()->latitude);
    }

    public function test_an_imported_publication_is_skipped_unless_forced(): void
    {
        $this->fakeMedicalInfoNet();
        $this->artisan('medical-info-net:import')->assertExitCode(0);

        $this->artisan('medical-info-net:import')
            ->expectsOutputToContain('取り込み済み')
            ->assertExitCode(0);

        $this->artisan('medical-info-net:import', ['--force' => true])
            ->expectsOutputToContain('3件')
            ->assertExitCode(0);
        $this->assertSame(3, MedicalInfoNetLocation::query()->count());
    }

    public function test_facilities_that_could_use_the_new_coordinates_are_marked_for_geocoding(): void
    {
        $this->fakeMedicalInfoNet();
        $town = MedicalFacility::factory()->create(['geocode_level' => GeocodeLevel::Town, 'latitude' => 35.68, 'longitude' => 139.76]);
        $residence = MedicalFacility::factory()->create(['geocode_level' => GeocodeLevel::Residence, 'latitude' => 35.68, 'longitude' => 139.76]);
        $unlocated = MedicalFacility::factory()->create();
        MedicalFacility::query()->toBase()->update(['geocoded_address' => 'old', 'updated_at' => '2026-01-01 00:00:00']);

        $this->artisan('medical-info-net:import')->assertExitCode(0);

        $this->assertNull($town->refresh()->geocoded_address);
        $this->assertNull($unlocated->refresh()->geocoded_address);
        $this->assertSame('old', $residence->refresh()->geocoded_address);
        $this->assertSame('2026-01-01 00:00:00', $town->updated_at->toDateTimeString());
    }

    /**
     * Two publications on the index page; 20251201 lacks the ".csv" in its
     * names, as the real page does for older ones.
     */
    private function fakeMedicalInfoNet(): void
    {
        $files = [
            '01-1_hospital_facility_info_20260601.csv.zip' => $this->zip(self::HEADER, []),
            '02-1_clinic_facility_info_20260601.csv.zip' => $this->zip(self::HEADER, [
                ['1', '医療法人　丸の内クリニック', '', '13', '101', '東京都千代田区丸の内１丁目９番１号　ビル', '35.681200', '139.767100'],
                ['2', '座標なしクリニック', '', '13', '101', '東京都千代田区丸の内１丁目１番１号', '0.0', '0.0'],
                ['3', '範囲外クリニック', '', '01', '101', '北海道札幌市中央区北１条西２丁目', '43.06', '141.35'],
            ]),
            '03-1_dental_facility_info_20260601.csv.zip' => $this->zip(self::HEADER, []),
            '05_pharmacy_20260601.csv.zip' => $this->zip(self::PHARMACY_HEADER, [
                ['4', '丸の内薬局', '', '13', '101', '東京都千代田区丸の内１丁目９番２号', '35.681300', '139.767200'],
            ]),
        ];
        $index = '<a href="/content/11121000/01-1_hospital_facility_info_20251201.zip">old</a>'
            .implode('', array_map(fn (string $name): string => "<a href=\"/content/11121000/{$name}\">{$name}</a>", array_keys($files)));

        Http::fake(function (Request $request) use ($files, $index) {
            $name = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return match (true) {
                str_ends_with($request->url(), 'newpage_43373.html') => Http::response($index),
                isset($files[$name]) => Http::response($files[$name]),
                default => Http::response('', 404),
            };
        });
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function zip(array $header, array $rows): string
    {
        $csv = "\xEF\xBB\xBF".implode("\r\n", array_map(
            fn (array $row): string => implode(',', array_map(fn (string $value): string => '"'.$value.'"', $row)),
            [$header, ...$rows],
        ))."\r\n";
        $path = (string) tempnam(sys_get_temp_dir(), 'mhlw');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('data.csv', $csv);
        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }
}
