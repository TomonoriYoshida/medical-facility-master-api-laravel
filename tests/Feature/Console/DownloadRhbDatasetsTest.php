<?php

namespace Tests\Feature\Console;

use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Models\RhbDatasetDownload;
use App\Services\Rhb\Download\BureauLinkResolverInterface;
use App\Services\Rhb\Download\HokkaidoLinkResolver;
use App\Services\Rhb\Download\ResolvedRhbDatasetLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadRhbDatasetsTest extends TestCase
{
    use RefreshDatabase;

    private const string HOKKAIDO_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/hokkaido/gyomu/gyomu/hoken_kikan/code_ichiran.html';

    private const string TOHOKU_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/tohoku/gyomu/gyomu/hoken_kikan/itiran.html';

    private const string KANTOSHINETSU_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/kantoshinetsu/chousa/shitei.html';

    private const string TOKAIHOKURIKU_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/tokaihokuriku/newpage_00287.html';

    private const string KINKI_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/kinki/tyousa/shinkishitei.html';

    private const string CHUGOKUSHIKOKU_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/chugokushikoku/chousaka/iryoukikanshitei.html';

    private const string SHIKOKU_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/shikoku/gyomu/gyomu/hoken_kikan/shitei/index.html';

    private const string KYUSHU_INDEX_URL = 'https://kouseikyoku.mhlw.go.jp/kyushu/gyomu/gyomu/hoken_kikan/index_00006.html';

    private const string ZIP_BODY = "PK\x03\x04fake-xlsx-content";

    private string $indexHtml = '';

    /** @var list<string> */
    private array $failingUrls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if ($request->url() === self::HOKKAIDO_INDEX_URL) {
                return Http::response($this->indexHtml);
            }

            if ($request->url() === self::TOHOKU_INDEX_URL) {
                return Http::response($this->fixture('rhb-tohoku-index.html'));
            }

            if ($request->url() === self::KANTOSHINETSU_INDEX_URL) {
                return Http::response($this->fixture('rhb-kantoshinetsu-index.html'));
            }

            if ($request->url() === self::TOKAIHOKURIKU_INDEX_URL) {
                return Http::response($this->fixture('rhb-tokaihokuriku-index.html'));
            }

            if ($request->url() === self::KINKI_INDEX_URL) {
                return Http::response($this->fixture('rhb-kinki-index.html'));
            }

            if ($request->url() === self::CHUGOKUSHIKOKU_INDEX_URL) {
                return Http::response($this->fixture('rhb-chugokushikoku-index.html'));
            }

            if ($request->url() === self::SHIKOKU_INDEX_URL) {
                return Http::response($this->fixture('rhb-shikoku-index.html'));
            }

            if ($request->url() === self::KYUSHU_INDEX_URL) {
                return Http::response($this->fixture('rhb-kyushu-index.html'));
            }

            if (in_array($request->url(), $this->failingUrls, true)) {
                return Http::response(status: 500);
            }

            return Http::response(self::ZIP_BODY);
        });
    }

    public function test_it_downloads_all_four_hokkaido_links_on_first_run(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(0);

        $this->assertDatabaseCount('rhb_dataset_downloads', 4);

        $medicalCount = RhbDatasetDownload::where('bureau_code', RhbBureau::Hokkaido)
            ->where('category', RhbCategory::Medical)
            ->count();
        $this->assertSame(2, $medicalCount);

        foreach (RhbDatasetDownload::all() as $download) {
            Storage::disk('local')->assertExists($download->local_path);
        }
    }

    public function test_it_skips_a_link_already_downloaded_at_the_same_published_date(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(0);
        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(0);

        $this->assertDatabaseCount('rhb_dataset_downloads', 4);
        // Run 1: 1 index fetch + 4 file fetches. Run 2: 1 index fetch, all
        // 4 links already downloaded so no further file fetches. 6 total.
        Http::assertSentCount(6);
    }

    public function test_a_new_published_date_for_the_same_filename_creates_a_new_row(): void
    {
        // Regression test: Hokkaido's document filenames stay fixed across
        // monthly updates -- only the page's own "current as of" date
        // changes -- so dedup must key on (filename, published_on), not
        // filename alone, or updates would be silently skipped forever.
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));
        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(0);

        $updatedHtml = str_replace('令和8年9月1日現在', '令和8年10月1日現在', $this->fixture('rhb-hokkaido-index.html'));
        $this->fakeHttp($updatedHtml);
        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(0);

        $this->assertDatabaseCount('rhb_dataset_downloads', 8);

        $filenames = RhbDatasetDownload::where('filename', '000499337.xlsx')->count();
        $this->assertSame(2, $filenames);
    }

    public function test_one_link_failing_does_not_prevent_the_others_from_downloading(): void
    {
        Storage::fake('local');
        $this->fakeHttp(
            $this->fixture('rhb-hokkaido-index.html'),
            failingUrls: ['https://kouseikyoku.mhlw.go.jp/hokkaido/000499343.xlsx'],
        );

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(1);

        $this->assertDatabaseCount('rhb_dataset_downloads', 3);
        $this->assertDatabaseMissing('rhb_dataset_downloads', ['filename' => '000499343.xlsx']);
    }

    public function test_an_unparseable_index_page_does_not_prevent_other_bureaus_from_downloading(): void
    {
        Storage::fake('local');
        $this->fakeHttp('<html><body>ページの構成が変わりました</body></html>');

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido', 'tohoku']])
            ->expectsOutputToContain('一覧ページの解析に失敗しました')
            ->assertExitCode(1);

        $this->assertFalse(RhbDatasetDownload::where('bureau_code', RhbBureau::Hokkaido)->exists());
        $this->assertTrue(RhbDatasetDownload::where('bureau_code', RhbBureau::Tohoku)->exists());
    }

    public function test_a_category_missing_from_the_index_page_is_reported_as_a_failure(): void
    {
        Storage::fake('local');
        $this->app->instance(HokkaidoLinkResolver::class, new class implements BureauLinkResolverInterface
        {
            public function resolve(string $html, string $baseUrl): array
            {
                return [
                    new ResolvedRhbDatasetLink(RhbCategory::Medical, "{$baseUrl}/medical.xlsx", 'medical.xlsx', CarbonImmutable::parse('2026-09-01')),
                    new ResolvedRhbDatasetLink(RhbCategory::Dental, "{$baseUrl}/dental.xlsx", 'dental.xlsx', CarbonImmutable::parse('2026-09-01')),
                ];
            }
        });

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])
            ->expectsOutputToContain('Pharmacy のリンクが見つかりません')
            ->assertExitCode(1);

        $this->assertDatabaseCount('rhb_dataset_downloads', 2);
    }

    public function test_only_bureaus_covering_the_scoped_prefectures_are_crawled(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));
        config(['rhb.scope.prefectures' => ['02', '39']]);

        $this->artisan('rhb:download')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(
            [RhbBureau::Tohoku, RhbBureau::Shikoku],
            RhbDatasetDownload::query()->distinct()->pluck('bureau_code')->all(),
        );
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::HOKKAIDO_INDEX_URL);
    }

    public function test_only_the_scoped_categories_are_downloaded(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));
        config(['rhb.scope.categories' => ['pharmacy']]);

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])->assertExitCode(0);

        $this->assertSame([RhbCategory::Pharmacy], RhbDatasetDownload::query()->distinct()->pluck('category')->all());
    }

    public function test_a_kyushu_zip_for_an_out_of_scope_prefecture_is_not_downloaded(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));
        config(['rhb.scope.prefectures' => ['41']]);

        $this->artisan('rhb:download')->assertExitCode(0);

        $this->assertSame(['000500311.zip'], RhbDatasetDownload::query()->distinct()->pluck('filename')->all());
        $this->assertSame(['41'], RhbDatasetDownload::query()->firstOrFail()->prefecture_codes);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '000500310.zip'));
    }

    public function test_a_bureau_outside_the_scope_is_rejected(): void
    {
        config(['rhb.scope.prefectures' => ['02']]);

        $this->artisan('rhb:download', ['--bureau' => ['hokkaido']])
            ->expectsOutputToContain('対象範囲（RHB_PREFECTURES）外の局です: hokkaido')
            ->assertExitCode(1);
    }

    public function test_without_a_bureau_filter_it_downloads_from_every_configured_bureau(): void
    {
        Storage::fake('local');
        $this->fakeHttp($this->fixture('rhb-hokkaido-index.html'));

        $this->artisan('rhb:download')->assertExitCode(0);

        $bureausSeen = RhbDatasetDownload::query()->distinct()->pluck('bureau_code');
        $this->assertContains(RhbBureau::Hokkaido, $bureausSeen);
        $this->assertContains(RhbBureau::Tohoku, $bureausSeen);
        $this->assertContains(RhbBureau::KantoShinetsu, $bureausSeen);
        $this->assertContains(RhbBureau::TokaiHokuriku, $bureausSeen);
        $this->assertContains(RhbBureau::Kinki, $bureausSeen);
        $this->assertContains(RhbBureau::ChugokuShikoku, $bureausSeen);
        $this->assertContains(RhbBureau::Shikoku, $bureausSeen);
        $this->assertContains(RhbBureau::Kyushu, $bureausSeen);
    }

    public function test_an_unknown_bureau_key_is_rejected(): void
    {
        $this->artisan('rhb:download', ['--bureau' => ['unknown']])->assertExitCode(1);

        $this->assertDatabaseCount('rhb_dataset_downloads', 0);
    }

    /**
     * @param  list<string>  $failingUrls
     */
    private function fakeHttp(string $indexHtml, array $failingUrls = []): void
    {
        $this->indexHtml = $indexHtml;
        $this->failingUrls = $failingUrls;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/{$name}"));
    }
}
