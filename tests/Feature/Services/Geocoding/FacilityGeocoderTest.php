<?php

namespace Tests\Feature\Services\Geocoding;

use App\Enums\GeocodeLevel;
use App\Enums\Prefecture;
use App\Models\Municipality;
use App\Services\Geocoding\FacilityGeocoder;
use App\Services\Geocoding\GeocodeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakesAbrDatasets;
use Tests\TestCase;

class FacilityGeocoderTest extends TestCase
{
    use FakesAbrDatasets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Municipality::factory()->create(['code' => '13101', 'prefecture_code' => '13', 'name' => '千代田区']);
        Municipality::factory()->create(['code' => '13205', 'prefecture_code' => '13', 'name' => '青梅市']);

        $this->fakeAbr([
            'mt_town/pref/mt_town_pref13.csv.zip' => [
                $this->abrTown('131016', '0001001', '内幸町', chome: '1', residential: true),
                $this->abrTown('131016', '0012002', '神田神保町', chome: '2'),
                $this->abrTown('131016', '0020000', '大字北堀'),
                $this->abrTown('131016', '0030000', '神明'),
                $this->abrTown('131016', '0030104', '神明', koaza: '宮北'),
                // Same machiaza_id as 神田神保町二丁目, in another municipality.
                $this->abrTown('132055', '0012002', '新町', chome: '2'),
            ],
            'mt_town_pos/pref/mt_town_pos_pref13.csv.zip' => [
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0001001'], 35.6700, 139.7500),
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0012002'], 35.6960, 139.7580),
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0020000'], 35.6900, 139.7400),
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0030000'], 35.6800, 139.7300),
            ],
            'mt_rsdtdsp_blk/pref/mt_rsdtdsp_blk_pref13.csv.zip' => [
                ['lg_code' => '131016', 'machiaza_id' => '0001001', 'blk_id' => '001', 'blk_num' => '1', 'ablt_date' => ''],
                ['lg_code' => '131016', 'machiaza_id' => '0001001', 'blk_id' => '002', 'blk_num' => '2', 'ablt_date' => ''],
            ],
            'mt_rsdtdsp_blk_pos/pref/mt_rsdtdsp_blk_pos_pref13.csv.zip' => [
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0001001', 'blk_id' => '001'], 35.6711, 139.7511),
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0001001', 'blk_id' => '002'], 35.6712, 139.7512),
            ],
            'mt_rsdtdsp_rsdt/pref/mt_rsdtdsp_rsdt_pref13.csv.zip' => [
                ['lg_code' => '131016', 'machiaza_id' => '0001001', 'blk_id' => '001', 'rsdt_id' => '003', 'rsdt2_id' => '', 'blk_num' => '1', 'rsdt_num' => '3', 'ablt_date' => ''],
            ],
            'mt_rsdtdsp_rsdt_pos/pref/mt_rsdtdsp_rsdt_pos_pref13.csv.zip' => [
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0001001', 'blk_id' => '001', 'rsdt_id' => '003', 'rsdt2_id' => ''], 35.6713, 139.7513),
            ],
            'mt_parcel/city/mt_parcel_city131016.csv.zip' => [
                ['lg_code' => '131016', 'machiaza_id' => '0012002', 'prc_id' => '000320000100000', 'prc_num1' => '32', 'prc_num2' => '1', 'ablt_date' => ''],
                ['lg_code' => '131016', 'machiaza_id' => '0012002', 'prc_id' => '000320001100000', 'prc_num1' => '32', 'prc_num2' => '11', 'ablt_date' => ''],
            ],
            'mt_parcel_pos/city/mt_parcel_pos_city131016.csv.zip' => [
                $this->abrPosition(['lg_code' => '131016', 'machiaza_id' => '0012002', 'prc_id' => '000320000100000'], 35.6961, 139.7581),
            ],
            'mt_parcel/city/mt_parcel_city132055.csv.zip' => [
                ['lg_code' => '132055', 'machiaza_id' => '0012002', 'prc_id' => '000320001100000', 'prc_num1' => '32', 'prc_num2' => '11', 'ablt_date' => ''],
            ],
            'mt_parcel_pos/city/mt_parcel_pos_city132055.csv.zip' => [
                $this->abrPosition(['lg_code' => '132055', 'machiaza_id' => '0012002', 'prc_id' => '000320001100000'], 35.7900, 139.3000),
            ],
        ]);
    }

    public function test_it_uses_the_most_precise_location_the_registry_has(): void
    {
        $results = app(FacilityGeocoder::class)->geocode(Prefecture::Tokyo, [
            1 => '千代田区内幸町一丁目1番3号',
            2 => '千代田区内幸町1-2-5 ビル3階',
            3 => '千代田区神田神保町二丁目32番地1',
            4 => '千代田区神田神保町２丁目３２番地１１',
            5 => '千代田区北堀649-1',
            6 => '千代田区存在しない町1',
            7 => '函館市本町1',
            8 => '千代田区神明宮北32',
        ]);

        $this->assertEquals(new GeocodeResult(GeocodeLevel::Residence, 35.6713, 139.7513), $results[1]);
        $this->assertEquals(new GeocodeResult(GeocodeLevel::Block, 35.6712, 139.7512), $results[2]);
        $this->assertEquals(new GeocodeResult(GeocodeLevel::Parcel, 35.6961, 139.7581), $results[3]);
        // 32番地11 has no position of its own; another 枝番 of 32 番地 is used,
        // never the same-numbered parcel of another municipality.
        $this->assertEquals(new GeocodeResult(GeocodeLevel::ParcelBase, 35.6961, 139.7581), $results[4]);
        $this->assertEquals(new GeocodeResult(GeocodeLevel::Town, 35.6900, 139.7400), $results[5]);
        $this->assertNull($results[6]);
        $this->assertNull($results[7]);
        // A 小字 without a position of its own falls back to its 大字's.
        $this->assertEquals(new GeocodeResult(GeocodeLevel::Town, 35.6800, 139.7300), $results[8]);
    }

    public function test_a_parcel_of_another_municipality_with_the_same_town_id_is_not_used(): void
    {
        $results = app(FacilityGeocoder::class)->geocode(Prefecture::Tokyo, [
            1 => '青梅市新町二丁目32番地11',
            2 => '千代田区神田神保町二丁目32番地11',
        ]);

        $this->assertEquals(new GeocodeResult(GeocodeLevel::Parcel, 35.7900, 139.3000), $results[1]);
        $this->assertEquals(new GeocodeResult(GeocodeLevel::ParcelBase, 35.6961, 139.7581), $results[2]);
    }

    public function test_a_missing_town_file_fails_instead_of_marking_every_facility_unlocatable(): void
    {
        $this->expectException(RuntimeException::class);

        app(FacilityGeocoder::class)->geocode(Prefecture::Hokkaido, [1 => '札幌市中央区北1条西2丁目']);
    }

    public function test_a_town_file_not_grouped_by_municipality_fails(): void
    {
        // Replaces setUp()'s fake, which would otherwise answer first.
        Http::swap(new Factory);
        $this->fakeAbr([
            'mt_town/pref/mt_town_pref13.csv.zip' => [
                $this->abrTown('131016', '0001001', '内幸町', chome: '1'),
                $this->abrTown('132055', '0012002', '新町', chome: '2'),
                $this->abrTown('131016', '0001002', '内幸町', chome: '2'),
            ],
        ]);

        $this->expectExceptionMessage('not listed together');

        app(FacilityGeocoder::class)->geocode(Prefecture::Tokyo, [1 => '千代田区内幸町二丁目1番1号']);
    }

    public function test_only_the_registry_files_that_are_needed_are_downloaded(): void
    {
        app(FacilityGeocoder::class)->geocode(Prefecture::Tokyo, [1 => '千代田区内幸町一丁目1番3号']);

        $this->assertArrayNotHasKey('mt_parcel/city/mt_parcel_city131016.csv.zip', $this->abrRequests);
        $this->assertArrayNotHasKey('mt_parcel/city/mt_parcel_city132055.csv.zip', $this->abrRequests);
    }
}
