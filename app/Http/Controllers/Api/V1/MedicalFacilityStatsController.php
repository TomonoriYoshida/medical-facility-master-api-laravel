<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\FacilityStatsGrouping;
use App\Http\Controllers\Api\V1\Concerns\BuildsMonthlyGroups;
use App\Http\Controllers\Api\V1\Concerns\FiltersMedicalFacilities;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityStatsRequest;
use App\Models\MedicalFacility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MedicalFacilityStatsController extends Controller
{
    use BuildsMonthlyGroups;
    use FiltersMedicalFacilities;
    use ProvidesAttribution;

    /** The data changes once a day (the import), so an hour of staleness is harmless. */
    private const int CACHE_SECONDS = 3600;

    /**
     * 施設数の集計
     *
     * 絞り込んだ施設を `group_by` ごとに数えます。絞り込みの条件は一覧APIと同じです。
     * 「期間内に新規開業した施設」は、`designation_reason=新規` と `designated_from`・`designated_to` で数えます
     * （保険医療機関の指定は6年ごとに更新されますが、指定年月日は最初の指定日のままです）。
     *
     * - `month`: 期間内のすべての月を古い順に返します（施設がない月は0）。
     * - `municipality`: 施設数の多い順です。住所から市区町村を判定できない施設は `key`・`label` が null の1件にまとめます。
     * - `department_category`: 施設数の多い順です。1つの施設が複数の診療科目に数えられるため、`count` の合計は `meta.total` と一致しません。
     */
    public function __invoke(MedicalFacilityStatsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        ksort($filters);
        $grouping = $request->grouping();

        $result = Cache::remember(
            'medical-facility-stats:'.sha1((string) json_encode($filters)),
            self::CACHE_SECONDS,
            function () use ($request, $filters, $grouping): array {
                $query = $this->applyFilters(MedicalFacility::query(), $filters);

                return [
                    'groups' => match ($grouping) {
                        FacilityStatsGrouping::Month => $this->byMonth(
                            $query,
                            $request->string('designated_from')->toString(),
                            $request->string('designated_to')->toString(),
                        ),
                        FacilityStatsGrouping::Municipality => $this->byMunicipality($query),
                        FacilityStatsGrouping::DepartmentCategory => $this->byDepartmentCategory($query),
                    },
                    'total' => (clone $query)->count(),
                ];
            },
        );

        return response()->json([
            /**
             * 集計結果。`key` は `month` なら `YYYY-MM`、`municipality` なら市区町村コード、
             * `department_category` なら診療科目のコード
             *
             * @var list<array{key: int|string|null, label: string|null, count: int}>
             */
            'data' => $result['groups'],
            'meta' => [
                /** 絞り込んだ施設の数 */
                'total' => (int) $result['total'],
                'group_by' => $grouping->value,
                'attribution' => $this->attribution(),
            ],
        ]);
    }

    /**
     * @param  Builder<MedicalFacility>  $query
     * @return list<array{key: string, label: string, count: int}>
     */
    private function byMonth(Builder $query, string $from, string $to): array
    {
        $counts = (clone $query)->toBase()
            ->selectRaw("DATE_FORMAT(designated_on, '%Y-%m') AS month, COUNT(*) AS facilities")
            ->groupBy('month')
            ->pluck('facilities', 'month');

        return $this->monthlyGroups($counts, $from, $to);
    }

    /**
     * @param  Builder<MedicalFacility>  $query
     * @return list<array{key: string|null, label: string|null, count: int}>
     */
    private function byMunicipality(Builder $query): array
    {
        $rows = (clone $query)->toBase()
            ->leftJoin('municipalities', 'municipalities.code', '=', 'medical_facilities.municipality_code')
            ->selectRaw('medical_facilities.municipality_code AS code, municipalities.name AS name, COUNT(*) AS facilities')
            ->groupBy('medical_facilities.municipality_code', 'municipalities.name')
            ->orderByDesc('facilities')
            ->orderBy('code')
            ->get();

        // Both columns are NULL for facilities whose municipality couldn't be resolved.
        return array_values($rows->map(fn (object $row): array => [
            'key' => is_string($row->code) ? $row->code : null,
            'label' => is_string($row->name) ? $row->name : null,
            'count' => (int) $row->facilities,
        ])->all());
    }

    /**
     * @param  Builder<MedicalFacility>  $query
     * @return list<array{key: int, label: string, count: int}>
     */
    private function byDepartmentCategory(Builder $query): array
    {
        // JSON_TABLE turns each facility's category array into one row per category.
        $rows = (clone $query)->toBase()
            ->crossJoin(DB::raw("JSON_TABLE(medical_facilities.department_categories, '$[*]' COLUMNS (category INT PATH '$')) AS departments"))
            ->selectRaw('departments.category AS code, COUNT(*) AS facilities')
            ->groupBy('departments.category')
            ->orderByDesc('facilities')
            ->orderBy('code')
            ->get();

        return array_values($rows->map(function (object $row): array {
            $category = DepartmentBaseCategory::from((int) $row->code);

            return [
                'key' => $category->value,
                'label' => $category->label(),
                'count' => (int) $row->facilities,
            ];
        })->all());
    }
}
