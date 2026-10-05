<?php

namespace Tests\Feature\Console;

use App\Models\PublicHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportPublicHolidaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_replaces_the_holidays_with_the_cabinet_offices_shift_jis_list(): void
    {
        config(['holidays.minimum_holidays' => 2]);
        PublicHoliday::factory()->create(['date' => '2025-01-01', 'name' => '古い一覧']);
        $this->fakeList("国民の祝日・休日月日,国民の祝日・休日名称\r\n2026/1/1,元日\r\n2026/9/22,休日\r\n2026/10/12,スポーツの日\r\n");

        $this->artisan('holidays:import')
            ->expectsOutputToContain('祝日を3日分（2026-10-12まで）取り込みました。')
            ->assertExitCode(0);

        $this->assertSame(
            ['2026-01-01' => '元日', '2026-09-22' => '休日', '2026-10-12' => 'スポーツの日'],
            PublicHoliday::query()->orderBy('date')->get()->mapWithKeys(fn (PublicHoliday $holiday): array => [$holiday->date->toDateString() => $holiday->name])->all(),
        );
    }

    public function test_a_list_with_too_few_holidays_fails_and_keeps_the_previous_one(): void
    {
        $previous = PublicHoliday::factory()->create();
        $this->fakeList("国民の祝日・休日月日,国民の祝日・休日名称\r\n2026/1/1,元日\r\n");

        $this->expectExceptionMessage('the previous list was kept');

        try {
            $this->artisan('holidays:import');
        } finally {
            $this->assertSame([$previous->id], PublicHoliday::query()->pluck('id')->all());
        }
    }

    private function fakeList(string $csv): void
    {
        Http::fake([config()->string('holidays.csv_url') => Http::response(mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'))]);
    }
}
