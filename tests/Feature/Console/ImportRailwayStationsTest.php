<?php

namespace Tests\Feature\Console;

use App\Models\MedicalFacility;
use App\Models\RailwayStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportRailwayStationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_replaces_the_stations_with_those_of_the_data(): void
    {
        $previous = RailwayStation::factory()->create();
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'latitude' => 35.7095, 'longitude' => 139.7530])
            ->forceFill(['municipality_code' => '13105'])->save();
        // Farther from the station than the facility above.
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'latitude' => 35.7000, 'longitude' => 139.7530])
            ->forceFill(['municipality_code' => '13101'])->save();
        // Nearer, but not located to a municipality.
        MedicalFacility::factory()->create(['prefecture_code' => '11', 'latitude' => 35.7090, 'longitude' => 139.7530]);
        $this->fakeStations([
            $this->feature('003529', '003529', '春日', '6号線三田線', '東京都', [[139.7530, 35.7080], [139.7530, 35.7100]]),
            // A station split into two pieces under one code.
            $this->feature('003539', '003529', '春日', '12号線大江戸線', '東京都', [[139.7520, 35.7090], [139.7522, 35.7092]]),
            $this->feature('003539', '003529', '春日', '12号線大江戸線', '東京都', [[139.7524, 35.7094], [139.7526, 35.7096]]),
            // Far from any facility.
            $this->feature('009210', '009210', '春日', '鹿児島線', '九州旅客鉄道', [[130.4600, 33.5300], [130.4602, 33.5302], [130.4604, 33.5304]]),
            [...$this->feature('000001', '000001', '点の駅', '線', '事業者', []), 'geometry' => ['type' => 'Point', 'coordinates' => [139.0, 35.0]]],
        ]);

        $this->artisan('stations:import')
            ->expectsOutputToContain('3駅を取り込みました（うち2駅は、近くの施設から都道府県・市区町村を付けました）')
            ->expectsOutputToContain('いずれかがない1件を除きました')
            ->assertExitCode(0);

        $this->assertModelMissing($previous);
        $mita = RailwayStation::query()->where('station_code', '003529')->sole();
        $this->assertSame('003529', $mita->group_code);
        $this->assertSame('春日', $mita->name);
        $this->assertSame('6号線三田線', $mita->line_name);
        $this->assertSame('東京都', $mita->operator_name);
        $this->assertSame('35.709000', $mita->latitude);
        $this->assertSame('139.753000', $mita->longitude);
        $this->assertSame('13', $mita->prefecture_code);
        $this->assertSame('13105', $mita->municipality_code);
        $oedo = RailwayStation::query()->where('station_code', '003539')->sole();
        $this->assertSame('35.709300', $oedo->latitude);
        $this->assertSame('139.752300', $oedo->longitude);
        $kagoshima = RailwayStation::query()->where('station_code', '009210')->sole();
        $this->assertSame('33.530200', $kagoshima->latitude);
        $this->assertNull($kagoshima->prefecture_code);
        $this->assertNull($kagoshima->municipality_code);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/N02-25_GML.zip'));
    }

    public function test_a_station_is_placed_halfway_along_its_platform(): void
    {
        $this->fakeStations([
            // Vertices crowded at the northern end; halfway is at 35.7050.
            $this->feature('000001', '000001', '北詰', '線', '事業者', [[139.7000, 35.7000], [139.7000, 35.7090], [139.7000, 35.7095], [139.7000, 35.7100]]),
        ]);

        $this->artisan('stations:import')->assertExitCode(0);

        $this->assertSame('35.705000', RailwayStation::query()->sole()->latitude);
    }

    public function test_the_lines_of_a_station_share_the_area_nearest_to_the_station(): void
    {
        // Each line's platform is nearest to a different facility, but the
        // station as a whole is nearest to the one in 13105.
        MedicalFacility::factory()->create(['prefecture_code' => '13', 'latitude' => 35.7000, 'longitude' => 139.7000])
            ->forceFill(['municipality_code' => '13105'])->save();
        MedicalFacility::factory()->create(['prefecture_code' => '14', 'latitude' => 35.7000, 'longitude' => 139.7110])
            ->forceFill(['municipality_code' => '14101'])->save();
        $this->fakeStations([
            $this->feature('000001', '000001', '境', 'A線', '事業者', [[139.6990, 35.7000], [139.6992, 35.7000]]),
            $this->feature('000002', '000001', '境', 'B線', '事業者', [[139.7060, 35.7000], [139.7062, 35.7000]]),
        ]);

        $this->artisan('stations:import')->assertExitCode(0);

        $this->assertSame(['13105', '13105'], RailwayStation::query()->orderBy('station_code')->pluck('municipality_code')->all());
    }

    public function test_a_failed_download_leaves_the_stations_as_they_were(): void
    {
        $station = RailwayStation::factory()->create();
        Http::fake(['nlftp.mlit.go.jp/*' => Http::response('', 404)]);

        $this->expectExceptionMessage('failed with HTTP 404');

        try {
            $this->artisan('stations:import');
        } finally {
            $this->assertModelExists($station);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $features
     */
    private function fakeStations(array $features): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ksj');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        // Named as in the real zip, which also holds the lines, Shift-JIS copies and GML.
        $zip->addFromString('N02-25_GML/Shift-JIS/N02-25_Station.dbf', '');
        $zip->addFromString('N02-25_GML/UTF-8/N02-25_RailroadSection.geojson', '{"type": "FeatureCollection", "features": []}');
        $zip->addFromString('N02-25_GML/UTF-8/N02-25_Station.geojson', (string) json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE));
        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        Http::fake(['nlftp.mlit.go.jp/*' => Http::response($contents)]);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $coordinates
     * @return array<string, mixed>
     */
    private function feature(string $code, string $groupCode, string $name, string $line, string $operator, array $coordinates): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['N02_001' => '12', 'N02_002' => '3', 'N02_003' => $line, 'N02_004' => $operator, 'N02_005' => $name, 'N02_005c' => $code, 'N02_005g' => $groupCode],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
        ];
    }
}
