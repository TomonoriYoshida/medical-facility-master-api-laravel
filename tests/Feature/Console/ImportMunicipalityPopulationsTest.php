<?php

namespace Tests\Feature\Console;

use App\Models\MunicipalityPopulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class ImportMunicipalityPopulationsTest extends TestCase
{
    use BuildsXlsxFixture;
    use RefreshDatabase;

    /** The header rows of the real file: 人口 over 男 / 女 / 計. */
    private const array HEADER = [
        ['令和8年1月1日住民基本台帳人口・世帯数、令和7年人口動態（市区町村別）（総計）'],
        ['', '', '', '令和8年', '令和8年', '令和8年'],
        ['', '', '', '人口', '人口', '人口', '世帯数'],
        ['', '', '', '男', '女', '計', '世帯数'],
        ['団体コード', '都道府県名', '市区町村名', '人', '人', '人', '世帯'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // The fixtures list a few municipalities, not all of Japan.
        config(['population.minimum_municipalities' => 1]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpXlsxFixtures();

        parent::tearDown();
    }

    public function test_it_imports_the_whole_population_by_municipality(): void
    {
        $this->fakeSoumu([
            ...self::HEADER,
            ['-', '合計', '-', '60396714', '63370904', '123767642'],
            ['130001', '東京都', '-', '6900000', '7100000', '14000000'],
            ['131016', '東京都', '千代田区', '34000', '35139', '69139'],
            ['131121', '東京都', '世田谷区', '440000', '488666', '928666'],
        ]);

        $this->artisan('population:import')
            ->expectsOutputToContain('2026-01-01時点の人口を2市区町村分')
            ->assertExitCode(0);

        // The 6-digit 団体コード ends with a check digit; prefecture and national totals are skipped.
        $this->assertSame(
            ['13101' => 69139, '13112' => 928666],
            MunicipalityPopulation::query()->orderBy('municipality_code')->pluck('population', 'municipality_code')->all(),
        );
        $this->assertSame('2026-01-01', MunicipalityPopulation::query()->firstOrFail()->as_of->toDateString());
    }

    public function test_an_imported_edition_is_skipped_unless_forced(): void
    {
        $this->fakeSoumu([...self::HEADER, ['131016', '東京都', '千代田区', '34000', '35139', '69139']]);
        $this->artisan('population:import')->assertExitCode(0);

        $this->artisan('population:import')
            ->expectsOutputToContain('取り込み済み')
            ->assertExitCode(0);

        $this->artisan('population:import', ['--force' => true])
            ->expectsOutputToContain('1市区町村分')
            ->assertExitCode(0);
    }

    public function test_a_file_whose_layout_changed_fails_and_keeps_the_previous_edition(): void
    {
        MunicipalityPopulation::factory()->create(['municipality_code' => '13101', 'population' => 68000, 'as_of' => '2025-01-01']);
        $this->fakeSoumu([
            ['団体コード', '都道府県名', '市区町村名', '人口'],
            ['131016', '東京都', '千代田区', '69139'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('layout may have changed');

        try {
            $this->artisan('population:import');
        } finally {
            $this->assertSame(68000, MunicipalityPopulation::query()->sole()->population);
        }
    }

    public function test_a_file_with_too_few_municipalities_fails_and_keeps_the_previous_edition(): void
    {
        config(['population.minimum_municipalities' => 3]);
        MunicipalityPopulation::factory()->create(['municipality_code' => '13101', 'population' => 68000, 'as_of' => '2025-01-01']);
        $this->fakeSoumu([...self::HEADER, ['131016', '東京都', '千代田区', '34000', '35139', '69139']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only 1 municipalities were read');

        try {
            $this->artisan('population:import');
        } finally {
            $this->assertSame(68000, MunicipalityPopulation::query()->sole()->population);
        }
    }

    public function test_a_code_that_lost_its_leading_zero_is_restored(): void
    {
        // As if the 団体コード of 札幌市中央区 (011011) were stored as a number.
        $this->fakeSoumu([...self::HEADER, ['11011', '北海道', '札幌市中央区', '120000', '126194', '246194']]);

        $this->artisan('population:import')->assertExitCode(0);

        $this->assertSame(246194, MunicipalityPopulation::query()->find('01101')?->population);
    }

    public function test_a_merged_population_header_is_found(): void
    {
        // 人口 written once over 男 / 女 / 計, as a merged cell is read.
        $header = self::HEADER;
        $header[2] = ['', '', '', '人口', '', '', '世帯数'];
        $this->fakeSoumu([...$header, ['131016', '東京都', '千代田区', '34000', '35139', '69139']]);

        $this->artisan('population:import')->assertExitCode(0);

        $this->assertSame(69139, MunicipalityPopulation::query()->find('13101')?->population);
    }

    /**
     * Serves a Shift_JIS index page (as 総務省 does) linking the other editions
     * of the data next to the file to import.
     *
     * @param  list<list<string>>  $rows
     */
    private function fakeSoumu(array $rows): void
    {
        $file = (string) file_get_contents($this->createXlsx($rows));
        $links = [
            '000000001.xlsx' => '【総計】令和8年住民基本台帳人口・世帯数、令和7年人口動態（都道府県別）',
            '000000002.xlsx' => '【総計】令和8住民基本台帳年齢階級別人口（市区町村別）',
            '000000003.xlsx' => '【日本人住民】令和8年住民基本台帳人口・世帯数、令和7年人口動態（市区町村別）',
            '000892952.xlsx' => '【総計】令和8年住民基本台帳人口・世帯数、令和7年人口動態（市区町村別）',
        ];
        $html = (string) mb_convert_encoding(
            '<html><body>'.implode('', array_map(
                fn (string $name, string $label): string => "<a href=\"/main_content/{$name}\">{$label} <img alt=\"EXCEL\"></a>",
                array_keys($links),
                $links,
            )).'</body></html>',
            'SJIS-win',
            'UTF-8',
        );

        Http::fake(function (Request $request) use ($file, $html) {
            return match (true) {
                str_ends_with($request->url(), 'jinkou_jinkoudoutai-setaisuu.html') => Http::response($html),
                str_ends_with($request->url(), '/main_content/000892952.xlsx') => Http::response($file),
                default => Http::response('wrong file', 404),
            };
        });
    }
}
