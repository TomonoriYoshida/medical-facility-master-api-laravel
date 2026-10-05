<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Models\MedicalFacility;
use App\Models\MedicalInfoNetLocation;
use App\Services\MedicalInfoNet\MedicalInfoNetMatcher;
use Illuminate\Http\JsonResponse;

class MedicalFacilityOpeningHoursController extends Controller
{
    use ProvidesAttribution;

    /**
     * 施設の診療時間
     *
     * 厚生労働省「医療情報ネット」のオープンデータ（年2回、6月・12月に更新）にある、1つの施設の診療時間と休診日を返します。
     * 地方厚生局のデータとは共通のコードがないため、同じ市区町村・施設種別で名称（または所在地）が一致する施設が1つだけ見つかったときに返し、
     * 見つからないときは `data` が `null` になります。`published_on` の時点の情報で、臨時の休診や最近の変更は含みません。
     *
     * `schedules` は同じ診療時間の診療科をまとめたもので、`slots` は時間帯（午前・午後など）ごとの曜日別の時刻です（`day` の `holiday` は祝日）。
     * 時刻は公開データのまま `HH:MM` で返し、終了が開始より早いもの（夜間など）もそのままです。薬局は `departments` が空で、受付時間はありません。
     * `closures` は定休日で、`weekly` が毎週の休み、`monthly` が「第2水曜」のような決まった週の休み、`holidays` が祝日に休むか、`other` がその他（自由記述）です。
     */
    public function __invoke(MedicalFacility $medicalFacility, MedicalInfoNetMatcher $matcher): JsonResponse
    {
        $location = $matcher->find($medicalFacility)?->load('schedule');

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
             */
            'published_on' => $location->published_on->toDateString(),
            /** @var list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'|'holiday', opens: string|null, closes: string|null, reception_opens: string|null, reception_closes: string|null}>}>}> */
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
            /** @var array{weekly: list<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'>, monthly: list<array{week: int, day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'}>, holidays: bool|null, other: string|null}|null */
            'closures' => $closures === null ? null : [
                'weekly' => $closures['weekly'],
                'monthly' => array_map(fn (array $closure): array => ['week' => $closure['week'], 'day' => $closure['day']], $closures['monthly']),
                'holidays' => $closures['holidays'],
                'other' => $closures['other'],
            ],
        ];
    }
}
