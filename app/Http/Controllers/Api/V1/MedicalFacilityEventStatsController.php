<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FacilityStatsGrouping;
use App\Enums\MedicalFacilityEventOrigin;
use App\Http\Controllers\Api\V1\Concerns\BuildsMonthlyGroups;
use App\Http\Controllers\Api\V1\Concerns\FiltersMedicalFacilities;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Api\V1\Concerns\RemembersStats;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityEventStatsRequest;
use App\Models\MedicalFacility;
use App\Models\MedicalFacilityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class MedicalFacilityEventStatsController extends Controller
{
    use BuildsMonthlyGroups;
    use FiltersMedicalFacilities;
    use ProvidesAttribution;
    use RemembersStats;

    /** The request fields that narrow the facilities rather than the events. */
    private const array FACILITY_FILTERS = ['prefecture_code', 'municipality_code', 'institution_type', 'department_category'];

    /**
     * 新規・廃止の集計
     *
     * 毎月の公開データを比較して見つかった施設の新規・廃止（変化の一覧と同じ「検知」の記録）を、
     * `group_by` ごとに数えます。初回取込と取り込み直し（再処理）の記録は含みません。
     * 記録は運用を始めてから蓄積されるため、最初の1〜2か月は0件になります。
     *
     * - `month`: 期間内のすべての月を古い順に返します（変化がない月は0）。月は変化が載った公開データの月で、実際の開業・廃止の月ではありません。
     * - `municipality`: 件数の多い順です。住所から市区町村を判定できない施設は `key`・`label` が null の1件にまとめます。
     *
     * 施設の絞り込み（都道府県・市区町村・種別・診療科目）は、施設の現在の内容で判定します。
     */
    public function __invoke(MedicalFacilityEventStatsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        ksort($filters);
        $grouping = $request->grouping();

        $result = $this->rememberStats(
            $request,
            'medical-facility-event-stats:'.sha1((string) json_encode($filters)),
            function () use ($request, $filters, $grouping): array {
                $query = $this->events($filters);

                return [
                    'groups' => match ($grouping) {
                        FacilityStatsGrouping::Month => $this->byMonth(
                            $query,
                            $request->string('occurred_from')->toString(),
                            $request->string('occurred_to')->toString(),
                        ),
                        FacilityStatsGrouping::Municipality => $this->byMunicipality($query),
                        // Rejected by the request; listed so the match stays exhaustive.
                        FacilityStatsGrouping::DepartmentCategory => [],
                    },
                    'total' => (clone $query)->count(),
                ];
            },
        );

        return response()->json([
            /**
             * 集計結果。`key` は `month` なら `YYYY-MM`、`municipality` なら市区町村コードで、
             * `label` はその名前、`count` は変化の数です
             *
             * @var list<array{key: string|null, label: string|null, count: int}>
             *
             * @example [{"key": "2026-08", "label": "2026年8月", "count": 0}, {"key": "2026-09", "label": "2026年9月", "count": 0}, {"key": "2026-10", "label": "2026年10月", "count": 483}]
             */
            'data' => $result['groups'],
            'meta' => [
                /**
                 * 絞り込んだ変化の数
                 *
                 * @example 483
                 */
                'total' => (int) $result['total'],
                /**
                 * 指定した集計の単位
                 *
                 * @example month
                 */
                'group_by' => $grouping->value,
                'attribution' => $this->attribution(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<MedicalFacilityEvent>
     */
    private function events(array $filters): Builder
    {
        $facilityFilters = Arr::only($filters, self::FACILITY_FILTERS);

        return MedicalFacilityEvent::query()
            // Only real differences between publications, like the event feed.
            ->where('origin', MedicalFacilityEventOrigin::Detected)
            ->where('event_type', $filters['event_type'])
            ->when($filters['occurred_from'] ?? null, fn ($query, $date) => $query->where('occurred_on', '>=', $date))
            ->when($filters['occurred_to'] ?? null, fn ($query, $date) => $query->where('occurred_on', '<=', $date))
            ->when($facilityFilters !== [], fn ($query) => $query->whereIn(
                'medical_facility_id',
                $this->applyFilters(MedicalFacility::query(), $facilityFilters)->select('id'),
            ));
    }

    /**
     * @param  Builder<MedicalFacilityEvent>  $query
     * @return list<array{key: string, label: string, count: int}>
     */
    private function byMonth(Builder $query, string $from, string $to): array
    {
        $counts = (clone $query)->toBase()
            ->selectRaw("DATE_FORMAT(occurred_on, '%Y-%m') AS month, COUNT(*) AS events")
            ->groupBy('month')
            ->pluck('events', 'month');

        return $this->monthlyGroups($counts, $from, $to);
    }

    /**
     * @param  Builder<MedicalFacilityEvent>  $query
     * @return list<array{key: string|null, label: string|null, count: int}>
     */
    private function byMunicipality(Builder $query): array
    {
        $rows = (clone $query)->toBase()
            ->join('medical_facilities', 'medical_facilities.id', '=', 'medical_facility_events.medical_facility_id')
            ->leftJoin('municipalities', 'municipalities.code', '=', 'medical_facilities.municipality_code')
            ->selectRaw('medical_facilities.municipality_code AS code, municipalities.name AS name, COUNT(*) AS events')
            ->groupBy('medical_facilities.municipality_code', 'municipalities.name')
            ->orderByDesc('events')
            ->orderBy('code')
            ->get();

        // Both columns are NULL for facilities whose municipality couldn't be resolved.
        return array_values($rows->map(fn (object $row): array => [
            'key' => is_string($row->code) ? $row->code : null,
            'label' => is_string($row->name) ? $row->name : null,
            'count' => (int) $row->events,
        ])->all());
    }
}
