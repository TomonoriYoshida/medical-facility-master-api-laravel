<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use Illuminate\Http\JsonResponse;

class MedicalFacilityOpeningHoursController extends Controller
{
    use ProvidesAttribution;

    /**
     * 施設の診療時間
     *
     * 厚生労働省「医療情報ネット」のオープンデータ（年2回、6月・12月に更新）にある、1つの施設の診療時間と休診日を返します。
     * 地方厚生局のデータとは共通のコードがないため、同じ市区町村・施設種別で名称（または所在地）が一致する施設が1つだけ見つかったときに返し、
     * 見つからないときは `data` が `null` になります（照合は毎朝行うため、新しく載った施設は翌朝から返ります）。`published_on` の時点の情報で、臨時の休診や最近の変更は含みません。
     *
     * `schedules` は同じ診療時間の診療科をまとめたもので、`slots` は時間帯（午前・午後など）ごとの曜日別の時刻です（`day` の `holiday` は祝日）。
     * 時刻は公開データのまま `HH:MM` で返し、終了が開始より早いもの（夜間など）もそのままです。薬局は `departments` が空で、受付時間はありません。
     * `closures` は定休日で、`weekly` が毎週の休み、`monthly` が「第2水曜」のような決まった週の休み、`holidays` が祝日に休むか、`other` がその他（自由記述）です。
     *
     * @param  MedicalFacility  $medicalFacility  施設のID（施設一覧の `id`）
     */
    public function __invoke(MedicalFacility $medicalFacility): JsonResponse
    {
        // Matched by facilities:assign-opening-hours, which open_at also uses.
        $location = $medicalFacility->medical_info_net_id === null ? null : MedicalInfoNetLocation::query()
            ->with('schedule')
            ->where('source_id', $medicalFacility->medical_info_net_id)
            ->first();

        return response()->json([
            'data' => $location === null ? null : $this->openingHours($location),
            'meta' => ['attribution' => $this->attribution()],
        ]);
    }

    /**
     * @return array{published_on: string, schedules: list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'|'holiday', opens: string|null, closes: string|null, reception_opens: string|null, reception_closes: string|null}>}>}>, closures: array{weekly: list<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'>, monthly: list<array{week: int, day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'}>, holidays: bool|null, other: string|null}|null}
     */
    private function openingHours(MedicalInfoNetLocation $location): array
    {
        $closures = $location->closures;

        // Rebuilt in a fixed key order: MySQL stores JSON object keys in its own.
        return [
            /**
             * 医療情報ネットの公開時点
             *
             * @format date
             *
             * @example 2026-06-01
             */
            'published_on' => $location->published_on->toDateString(),
            /**
             * 診療時間。`departments` は同じ診療時間の診療科、`slots` は時間帯（`number` は医療情報ネットでの時間帯の番号）ごとの曜日別の時刻。
             * `opens`・`closes` は診療時間、`reception_opens`・`reception_closes` は受付時間（`HH:MM`）
             *
             * @var list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'|'holiday', opens: string|null, closes: string|null, reception_opens: string|null, reception_closes: string|null}>}>}>
             *
             * @example [{"departments": ["内科"], "slots": [{"number": 1, "days": [{"day": "mon", "opens": "09:00", "closes": "17:00", "reception_opens": "09:00", "reception_closes": "16:30"}, {"day": "tue", "opens": "09:00", "closes": "17:00", "reception_opens": "09:00", "reception_closes": "16:30"}, {"day": "wed", "opens": "09:00", "closes": "17:00", "reception_opens": "09:00", "reception_closes": "16:30"}, {"day": "thu", "opens": "09:00", "closes": "17:00", "reception_opens": "09:00", "reception_closes": "16:30"}, {"day": "fri", "opens": "09:00", "closes": "17:00", "reception_opens": "09:00", "reception_closes": "16:30"}, {"day": "sat", "opens": "09:00", "closes": "12:30", "reception_opens": "09:00", "reception_closes": "12:00"}]}]}]
             */
            'schedules' => array_map(fn (array $schedule): array => [
                'departments' => $schedule['departments'],
                'slots' => array_map(fn (array $slot): array => [
                    'number' => $slot['number'],
                    'days' => array_map(fn (array $day): array => [
                        'day' => $day['day'],
                        'opens' => $day['opens'],
                        'closes' => $day['closes'],
                        'reception_opens' => $day['reception_opens'],
                        'reception_closes' => $day['reception_closes'],
                    ], $slot['days']),
                ], $schedule['slots']),
            ], $location->schedule->schedules ?? []),
            /**
             * 定休日。`weekly` は毎週の休み、`monthly` は決まった週の休み（`week` が2なら第2週）、
             * `holidays` は祝日に休むか（記載がなければnull）、`other` はその他（自由記述）。定休日の記載がなければnull
             *
             * @var array{weekly: list<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'>, monthly: list<array{week: int, day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'}>, holidays: bool|null, other: string|null}|null
             *
             * @example {"weekly": ["sun"], "monthly": [{"week": 2, "day": "sat"}], "holidays": true, "other": "01月01日，01月02日，01月03日，01月04日，02月12日，12月29日，12月30日，12月31日"}
             */
            'closures' => $closures === null ? null : [
                'weekly' => $closures['weekly'],
                'monthly' => array_map(fn (array $closure): array => ['week' => $closure['week'], 'day' => $closure['day']], $closures['monthly']),
                'holidays' => $closures['holidays'],
                'other' => $closures['other'],
            ],
        ];
    }
}
