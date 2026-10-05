<?php

namespace Tests\Unit\Services\MedicalInfoNet;

use App\Services\MedicalInfoNet\MedicalInfoNetHours;
use PHPUnit\Framework\TestCase;

class MedicalInfoNetHoursTest extends TestCase
{
    public function test_departments_with_the_same_hours_are_grouped_and_empty_slots_left_out(): void
    {
        $morning = ['月_診療開始時間' => '09:00', '月_診療終了時間' => '12:30', '月_外来受付開始時間' => '08:45', '月_外来受付終了時間' => '12:00'];

        $schedules = (new MedicalInfoNetHours)->fromDepartmentRows([
            $this->departmentRow('内科', '1', $morning),
            $this->departmentRow('内科', '2', ['祝_診療開始時間' => '14:00', '祝_診療終了時間' => '17:00']),
            $this->departmentRow('内科', '3', []),
            $this->departmentRow('小児科', '1', $morning),
            $this->departmentRow('小児科', '2', ['祝_診療開始時間' => '14:00', '祝_診療終了時間' => '17:00']),
            $this->departmentRow('皮膚科', '1', [...$morning, '火_診療開始時間' => '9時', '火_診療終了時間' => '']),
        ]);

        $this->assertSame([
            [
                'departments' => ['内科', '小児科'],
                'slots' => [
                    ['number' => 1, 'days' => [['day' => 'mon', 'opens' => '09:00', 'closes' => '12:30', 'reception_opens' => '08:45', 'reception_closes' => '12:00']]],
                    ['number' => 2, 'days' => [['day' => 'holiday', 'opens' => '14:00', 'closes' => '17:00', 'reception_opens' => null, 'reception_closes' => null]]],
                ],
            ],
            [
                // Unreadable times are dropped.
                'departments' => ['皮膚科'],
                'slots' => [
                    ['number' => 1, 'days' => [['day' => 'mon', 'opens' => '09:00', 'closes' => '12:30', 'reception_opens' => '08:45', 'reception_closes' => '12:00']]],
                ],
            ],
        ], $schedules);
    }

    public function test_a_pharmacys_slots_form_one_schedule_without_departments(): void
    {
        $schedules = (new MedicalInfoNetHours)->fromPharmacyRow([
            '月_開店時間帯1_開始時間' => '09:00',
            '月_開店時間帯1_終了時間' => '13:00',
            '月_開店時間帯2_開始時間' => '',
            '月_開店時間帯2_終了時間' => '',
            '月_開店時間帯3_開始時間' => '14:00',
            '月_開店時間帯3_終了時間' => '18:00',
        ]);

        $this->assertSame([[
            'departments' => [],
            'slots' => [
                ['number' => 1, 'days' => [['day' => 'mon', 'opens' => '09:00', 'closes' => '13:00', 'reception_opens' => null, 'reception_closes' => null]]],
                ['number' => 3, 'days' => [['day' => 'mon', 'opens' => '14:00', 'closes' => '18:00', 'reception_opens' => null, 'reception_closes' => null]]],
            ],
        ]], $schedules);
        $this->assertSame([], (new MedicalInfoNetHours)->fromPharmacyRow([]));
    }

    public function test_a_facilitys_flags_are_read_as_0_for_closed(): void
    {
        $row = [
            ...$this->flags('毎週決まった曜日に休診（%s）', ['水' => '0', '日' => '0']),
            ...$this->flags('決まった週に休診（定期週）第2週（%s）', ['土' => '0', '水' => '0']),
            '祝日に休診' => '0',
            'その他の休診日（gw、お盆等）' => ' 年末年始（改行）お盆 ',
        ];

        $this->assertSame([
            'weekly' => ['wed', 'sun'],
            // Wednesdays are off every week anyway.
            'monthly' => [['week' => 2, 'day' => 'sat']],
            'holidays' => true,
            'other' => "年末年始\nお盆",
        ], (new MedicalInfoNetHours)->closures($row));
    }

    public function test_a_pharmacys_flags_use_their_own_columns(): void
    {
        $row = [
            ...$this->flags('定期閉店毎週（%s）', ['日' => '0']),
            ...$this->flags('定期閉店第３週（%s）', ['木' => '0']),
            '祝日' => '1',
            'その他の閉店日（gw、お盆等）' => '',
        ];

        $this->assertSame([
            'weekly' => ['sun'],
            'monthly' => [['week' => 3, 'day' => 'thu']],
            'holidays' => false,
            'other' => null,
        ], (new MedicalInfoNetHours)->closures($row));
    }

    public function test_a_row_without_any_days_off_information_has_none(): void
    {
        $this->assertNull((new MedicalInfoNetHours)->closures([
            ...$this->flags('毎週決まった曜日に休診（%s）', [], ''),
            '祝日に休診' => '',
            'その他の休診日（gw、お盆等）' => '',
        ]));
    }

    /**
     * @param  array<string, string>  $times
     * @return array<string, string>
     */
    private function departmentRow(string $department, string $slot, array $times): array
    {
        return ['ID' => '1', '診療科目コード' => '01001', '診療科目名' => $department, '診療時間帯' => $slot, ...$times];
    }

    /**
     * Every weekday's column, open ('1') unless given.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function flags(string $column, array $values, string $default = '1'): array
    {
        $flags = [];

        foreach (['月', '火', '水', '木', '金', '土', '日'] as $day) {
            $flags[sprintf($column, $day)] = $values[$day] ?? $default;
        }

        return $flags;
    }
}
