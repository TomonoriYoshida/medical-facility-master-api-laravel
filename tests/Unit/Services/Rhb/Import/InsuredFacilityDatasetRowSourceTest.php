<?php

namespace Tests\Unit\Services\Rhb\Import;

use App\Enums\InstitutionType;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Services\Rhb\Import\InsuredFacilityDatasetRowSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsXlsxFixture;

class InsuredFacilityDatasetRowSourceTest extends TestCase
{
    use BuildsXlsxFixture;

    protected function tearDown(): void
    {
        $this->cleanUpXlsxFixtures();

        parent::tearDown();
    }

    public function test_it_streams_mapped_attribute_arrays_end_to_end_from_a_xlsx_file(): void
    {
        $path = $this->createXlsx([
            ['コード内容別医療機関一覧表'],
            ['[令和 8年 9月 1日現在　医科　現存/休止]'],
            [
                '1', '01,1248,9', '医療法人　愛全病院',
                '〒005－0813札幌市南区川沿１３条２丁目１番３８号', '011-571-5670',
                '医療法人　愛全会', '松原　泉', '昭47. 3. 1', "療養\u{3000}\u{3000} 206", '病院',
            ],
            ['', '', '', '', '', '', '', '新規', '', '現存'],
            [
                '2', '01,1274,5', '平松記念病院',
                '〒064－8536札幌市中央区南２２条西１４丁目１番２０号', '011-561-0708',
                '慈藻会', '傅田　健三', '昭48. 12. 1', '', '診療所',
            ],
        ]);

        $source = new InsuredFacilityDatasetRowSource;
        $mapped = iterator_to_array($source->rows($path, RhbCategory::Medical, RhbBureau::Hokkaido, '01'));

        $this->assertCount(2, $mapped);
        $this->assertSame('0112489', $mapped[0]['facility_code']);
        $this->assertSame(InstitutionType::Hospital, $mapped[0]['institution_type']);
        $this->assertSame(['療養' => 206], $mapped[0]['bed_counts']);
        $this->assertSame('0112745', $mapped[1]['facility_code']);
        $this->assertSame(InstitutionType::Clinic, $mapped[1]['institution_type']);
    }
}
