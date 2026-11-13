<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Municipality;
use App\Models\RailwayStation;
use App\Services\Text\StationNameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RailwayStationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_stations_of_a_name_with_their_lines_grouped(): void
    {
        Municipality::factory()->create(['code' => '13105', 'prefecture_code' => '13', 'name' => '文京区']);
        $this->station('003529', '003529', '春日', '6号線三田線', '13', '13105', 35.7090, 139.7530);
        $this->station('003539', '003529', '春日', '12号線大江戸線', '13', '13105', 35.7094, 139.7524);
        $this->station('009210', '009210', '春日', '鹿児島線', null, null, 33.5302, 130.4602);
        $this->station('003542', '003542', '後楽園', '7号線南北線', '13', '13105', 35.7080, 139.7520);

        $response = $this->getJson('/api/v1/stations?name=春日');

        $response->assertOk();
        $this->assertSame([
            [
                'name' => '春日',
                'prefecture' => ['code' => '13', 'label' => '東京都'],
                'municipality' => ['code' => '13105', 'label' => '文京区'],
                'latitude' => 35.7092,
                'longitude' => 139.7527,
                'lines' => [['operator' => '東京都', 'line' => '6号線三田線'], ['operator' => '東京都', 'line' => '12号線大江戸線']],
            ],
            [
                'name' => '春日',
                'prefecture' => null,
                'municipality' => null,
                'latitude' => 33.5302,
                'longitude' => 130.4602,
                'lines' => [['operator' => '東京都', 'line' => '鹿児島線']],
            ],
        ], $response->json('data'));
        $response->assertJsonPath('meta.attribution.station_source.url', 'https://nlftp.mlit.go.jp/ksj/gml/datalist/KsjTmplt-N02-2025.html');
        $response->assertHeader('Cache-Control', 'max-age=86400, public');
    }

    public function test_narrows_same_named_stations_by_prefecture(): void
    {
        $this->station('003529', '003529', '春日', '6号線三田線', '13', '13105', 35.7090, 139.7530);
        $this->station('009210', '009210', '春日', '鹿児島線', '40', '40218', 33.5302, 130.4602);

        $this->assertSame(['鹿児島線'], $this->getJson('/api/v1/stations?name=春日&prefecture_code=40')->json('data.*.lines.0.line'));
    }

    public function test_matches_a_name_written_differently(): void
    {
        $this->station('003701', '003701', '市ケ谷', '10号線新宿線', '13', '13104', 35.6920, 139.7360);
        $this->station('008772', '008772', '松山市駅', '花園線', '38', '38201', 33.8360, 132.7650);
        $this->station('008773', '008773', '松山市', '高浜線', '38', '38201', 33.8362, 132.7652);

        $this->assertSame(['市ケ谷'], $this->getJson('/api/v1/stations?name='.urlencode('市ヶ谷駅'))->json('data.*.name'));
        $this->assertSame(['市ケ谷'], $this->getJson('/api/v1/stations?name='.urlencode('市ヶ谷'))->json('data.*.name'));
        // A name ending in 駅 is first looked up as it is.
        $this->assertSame(['松山市駅'], $this->getJson('/api/v1/stations?name='.urlencode('松山市駅'))->json('data.*.name'));
    }

    public function test_a_name_without_its_suffix_is_preferred_to_a_prefix(): void
    {
        $this->station('009001', '009001', '高知', '土讃線', '39', '39201', 33.5670, 133.5430);
        $this->station('009002', '009002', '高知駅前', '駅前線', '39', '39201', 33.5660, 133.5432);

        $this->assertSame(['高知'], $this->getJson('/api/v1/stations?name='.urlencode('高知駅'))->json('data.*.name'));
    }

    public function test_names_differing_in_voicing_or_script_do_not_match_exactly(): void
    {
        $this->station('009101', '009101', 'ハマ', '線', '13', '13104', 35.6900, 139.6992);
        $this->station('009102', '009102', 'バマ', '線', '13', '13104', 35.6910, 139.6992);
        $this->station('009103', '009103', 'はま', '線', '13', '13104', 35.6920, 139.6992);

        $this->assertSame(['ハマ'], $this->getJson('/api/v1/stations?name='.urlencode('ハマ'))->json('data.*.name'));
    }

    public function test_falls_back_to_the_stations_starting_with_the_name(): void
    {
        $this->station('003700', '003700', '新宿', '京王線', '13', '13104', 35.6900, 139.6992);
        $this->station('003701', '003701', '新宿三丁目', '13号線副都心線', '13', '13104', 35.6905, 139.7050);
        $this->station('003702', '003702', '西新宿', '2号線丸ノ内線', '13', '13104', 35.6940, 139.6925);
        $this->station('003703', '003703', '100%駅', '線', '13', '13104', 35.6940, 139.6925);

        $this->assertSame(['新宿'], $this->getJson('/api/v1/stations?name=新宿')->json('data.*.name'));
        $this->assertSame(['新宿三丁目'], $this->getJson('/api/v1/stations?name=新宿三')->json('data.*.name'));
        $this->assertSame([], $this->getJson('/api/v1/stations?name='.urlencode('%'))->json('data'));
    }

    public function test_prefix_matches_are_returned_shortest_name_first(): void
    {
        $this->station('000001', '000001', '新大阪', '線', '27', '27123', 34.7330, 135.5000);
        $this->station('000002', '000002', '新札幌北口', '線', '01', '01103', 43.0390, 141.4720);
        $this->station('000003', '000003', '新宿', '線', '13', '13104', 35.6900, 139.6992);
        $this->station('000004', '000004', '新函館', '線', null, null, 41.9050, 140.6480);

        $this->assertSame(['新宿', '新大阪', '新函館', '新札幌北口'], $this->getJson('/api/v1/stations?name=新')->json('data.*.name'));
    }

    public function test_returns_422_without_a_name(): void
    {
        $this->getJson('/api/v1/stations')->assertJsonValidationErrors('name');
        $this->getJson('/api/v1/stations?name=春日&prefecture_code=99')->assertJsonValidationErrors('prefecture_code');
    }

    private function station(string $code, string $groupCode, string $name, string $line, ?string $prefectureCode, ?string $municipalityCode, float $latitude, float $longitude): void
    {
        RailwayStation::factory()->create([
            'station_code' => $code,
            'group_code' => $groupCode,
            'name' => $name,
            'name_normalized' => app(StationNameNormalizer::class)->normalize($name),
            'line_name' => $line,
            'operator_name' => '東京都',
            'prefecture_code' => $prefectureCode,
            'municipality_code' => $municipalityCode,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
