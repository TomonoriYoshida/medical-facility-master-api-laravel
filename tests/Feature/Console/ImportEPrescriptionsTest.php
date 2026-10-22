<?php

namespace Tests\Feature\Console;

use App\Enums\InstitutionType;
use App\Models\MedicalFacility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsXlsxFixture;
use Tests\TestCase;

class ImportEPrescriptionsTest extends TestCase
{
    use BuildsXlsxFixture;
    use RefreshDatabase;

    /** The rows above the data in the real file: the title, the counts, and the header. */
    private const array HEADER = [
        ['電子処方箋利用 参加医療機関・薬局リスト'],
        ['92501', '92501'],
        ['都道府県名', '医療機関区分', '医療機関等コード', '電子処方箋の運用開始日', '電子処方箋としてのリフィル処方箋の運用開始日', '医療機関名称（カナ）', '医療機関名称（漢字）', '郵便番号', '電話番号', '住所'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // The fixtures list a few facilities, not all of Japan.
        config(['e_prescriptions.minimum_facilities' => 1]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpXlsxFixtures();

        parent::tearDown();
    }

    public function test_it_sets_the_dates_of_listed_facilities_by_their_medical_institution_code(): void
    {
        $hospital = $this->facility('01', InstitutionType::Hospital, '0114386');
        $dental = $this->facility('13', InstitutionType::DentalClinic, '0100001');
        $pharmacy = $this->facility('47', InstitutionType::Pharmacy, '2341433');
        $unlisted = $this->facility('01', InstitutionType::Clinic, '0999999');
        $this->fakeList([
            // Dates are Excel serial numbers (46235 is 2026-08-01), or text in
            // some editions; a code stored as a number loses its leading zero.
            ['北海道', '医科（病院）', '114386', '46235', '', 'ｼﾞｴｲｱｰﾙｻﾂﾎﾟﾛﾋﾞﾖｳｲﾝ', 'ＪＲ札幌病院'],
            ['東京都', '歯科（診療所）', '0100001', '45381', '46235.5'],
            ['沖縄県', '薬局', '2341433', '2024/03/30 0:00:00', ''],
            // The same 7 digits under another 点数表 is another facility.
            ['北海道', '歯科（診療所）', '0999999', '45381', ''],
        ]);

        $this->artisan('e-prescriptions:import')
            ->expectsOutputToContain('2026-10-11時点の電子処方箋の対応施設を取り込みました（一覧4件のうち3件が一致、3件を更新）。')
            ->assertExitCode(0);

        $this->assertSame(['2026-08-01', null], $this->dates($hospital));
        $this->assertSame(['2024-03-30', '2026-08-01'], $this->dates($dental));
        $this->assertSame(['2024-03-30', null], $this->dates($pharmacy));
        $this->assertSame([null, null], $this->dates($unlisted));
    }

    public function test_only_changed_facilities_move_their_updated_at_and_unlisted_ones_are_cleared(): void
    {
        $unchanged = $this->facility('01', InstitutionType::Hospital, '0114386', startedOn: '2026-08-01');
        $dropped = $this->facility('01', InstitutionType::Clinic, '0100002', startedOn: '2024-03-29');
        $this->fakeList([['北海道', '医科（病院）', '0114386', '46235', '']]);
        $this->travelTo(Carbon::parse('2026-10-17 19:20:00'));

        $this->artisan('e-prescriptions:import')->assertExitCode(0);

        $this->assertSame(['2026-08-01', null], $this->dates($unchanged));
        $this->assertTrue($unchanged->fresh()?->updated_at?->lt(now()));
        $this->assertSame([null, null], $this->dates($dropped));
        $this->assertTrue($dropped->fresh()?->updated_at?->eq(now()));
    }

    public function test_an_imported_list_is_skipped_unless_forced(): void
    {
        $this->fakeList([['北海道', '医科（病院）', '0114386', '46235', '']]);
        $this->artisan('e-prescriptions:import')->assertExitCode(0);

        $this->artisan('e-prescriptions:import')
            ->expectsOutputToContain('取り込み済み')
            ->assertExitCode(0);
        $this->artisan('e-prescriptions:import --force')
            ->expectsOutputToContain('取り込みました')
            ->assertExitCode(0);
    }

    public function test_a_list_with_too_few_facilities_fails_and_keeps_the_dates(): void
    {
        config(['e_prescriptions.minimum_facilities' => 2]);
        $facility = $this->facility('01', InstitutionType::Clinic, '0100002', startedOn: '2024-03-29');
        $this->fakeList([['北海道', '医科（病院）', '0114386', '46235', '']]);

        $this->expectExceptionMessage('the previous dates were kept');

        try {
            $this->artisan('e-prescriptions:import');
        } finally {
            $this->assertSame(['2024-03-29', null], $this->dates($facility));
        }
    }

    public function test_a_list_whose_layout_changed_fails(): void
    {
        $this->fakeList([['北海道', '医科（病院）', '0114386', '46235', '']], header: [['都道府県', '区分', 'コード']]);

        $this->expectExceptionMessage("the file's layout may have changed");

        $this->artisan('e-prescriptions:import');
    }

    private function facility(string $prefectureCode, InstitutionType $type, string $facilityCode, ?string $startedOn = null): MedicalFacility
    {
        return MedicalFacility::factory()->create([
            'prefecture_code' => $prefectureCode,
            'institution_type' => $type,
            'facility_code' => $facilityCode,
            'e_prescription_started_on' => $startedOn,
            'updated_at' => Carbon::parse('2026-10-01 00:00:00'),
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function dates(MedicalFacility $facility): array
    {
        $facility = $facility->fresh();

        return [$facility?->e_prescription_started_on?->toDateString(), $facility?->e_prescription_refill_started_on?->toDateString()];
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<list<string>>|null  $header
     */
    private function fakeList(array $rows, ?array $header = null): void
    {
        $xlsx = (string) file_get_contents($this->createXlsx([...($header ?? self::HEADER), ...$rows], '参加医療機関・薬局リスト'));

        Http::fake([
            config()->string('e_prescriptions.page_url') => Http::response(
                '<p><a data-icon="excel" target="_blank" href="/content/001148730.xlsx" target="_blank">電子処方せん対応の医療機関・薬局リスト（2026年10月11日時点）［8.2MB］</a></p>',
            ),
            // A fresh response each time: a response's body is read only once.
            'https://www.mhlw.go.jp/content/001148730.xlsx' => fn () => Http::response($xlsx),
        ]);
    }
}
