<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\PublicHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHolidayControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_this_and_next_years_holidays_in_date_order_by_default(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        foreach (['2027-11-23' => '勤労感謝の日', '2026-01-01' => '元日', '2025-12-31' => '範囲外', '2028-01-01' => '範囲外'] as $date => $name) {
            PublicHoliday::factory()->create(['date' => $date, 'name' => $name]);
        }

        $response = $this->getJson('/api/v1/holidays');

        $response->assertOk();
        $this->assertSame([['date' => '2026-01-01', 'name' => '元日'], ['date' => '2027-11-23', 'name' => '勤労感謝の日']], $response->json('data'));
        $response->assertJsonPath('meta.attribution.holiday_source.url', 'https://www8.cao.go.jp/chosei/shukujitsu/gaiyou.html');
        $response->assertHeader('Cache-Control', 'max-age=86400, public');
    }

    public function test_returns_the_holidays_within_the_given_range_inclusive(): void
    {
        foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-10-12'] as $date) {
            PublicHoliday::factory()->create(['date' => $date]);
        }

        $this->assertSame(
            ['2026-09-22', '2026-09-23'],
            $this->getJson('/api/v1/holidays?from=2026-09-22&to=2026-09-23')->json('data.*.date'),
        );
    }

    public function test_returns_422_for_an_invalid_range(): void
    {
        $this->getJson('/api/v1/holidays?from=2026-10-01&to=2026-09-01')->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/holidays?from=2026/10/01')->assertJsonValidationErrors('from');
    }
}
