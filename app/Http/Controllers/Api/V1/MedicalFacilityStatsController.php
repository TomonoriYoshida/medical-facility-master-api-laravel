<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\FacilityStatsGrouping;
use App\Http\Controllers\Api\V1\Concerns\BuildsMonthlyGroups;
use App\Http\Controllers\Api\V1\Concerns\FiltersMedicalFacilities;
use App\Http\Controllers\Api\V1\Concerns\ProvidesAttribution;
use App\Http\Controllers\Api\V1\Concerns\RemembersStats;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MedicalFacilityStatsRequest;
use App\Models\MedicalFacility;
use App\Models\MunicipalityPopulation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MedicalFacilityStatsController extends Controller
{
    use BuildsMonthlyGroups;
    use FiltersMedicalFacilities;
    use ProvidesAttribution;
    use RemembersStats;

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
     *
     * `municipality` のときは、各市区町村の人口（総務省「住民基本台帳に基づく人口」、`meta.population_as_of` 時点）と
     * 人口1万人あたりの件数（`count_per_10k`）も返します。住民登録上の人口のため、昼間人口の多い都心部では高く出ます。
     * ほかの `group_by` と、人口がわからない市区町村では null です。
     */
    public function __invoke(MedicalFacilityStatsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        ksort($filters);
        $grouping = $request->grouping();

        $result = $this->rememberStats(
            $request,
            // v2: entries cached before the population fields lack them.
            'medical-facility-stats:v2:'.sha1((string) json_encode($filters)),
            function () use ($request, $filters, $grouping): array {
                $query = $this->applyFilters(MedicalFacility::query(), $filters);

                $groups = match ($grouping) {
                    FacilityStatsGrouping::Month => $this->byMonth(
                        $query,
                        $request->string('designated_from')->toString(),
                        $request->string('designated_to')->toString(),
                    ),
                    FacilityStatsGrouping::Municipality => $this->byMunicipality($query),
                    FacilityStatsGrouping::DepartmentCategory => $this->byDepartmentCategory($query),
                };

                return [
                    'groups' => array_map($this->withPerCapita(...), $groups),
                    'total' => (clone $query)->count(),
                    'population_as_of' => $grouping === FacilityStatsGrouping::Municipality
                        ? MunicipalityPopulation::query()->max('as_of')
                        : null,
                ];
            },
        );

        return response()->json([
            /**
             * 集計結果。`key` は `month` なら `YYYY-MM`、`municipality` なら市区町村コード、
             * `department_category` なら診療科目のコード
             *
             * @var list<array{key: int|string|null, label: string|null, count: int, population: int|null, count_per_10k: float|null}>
             */
            'data' => $result['groups'],
            'meta' => [
                /** 絞り込んだ施設の数 */
                'total' => (int) $result['total'],
                'group_by' => $grouping->value,
                /**
                 * `population` の基準日（YYYY-MM-DD）。`municipality` 以外、または人口が未取込なら null
                 *
                 * @var string|null
                 */
                'population_as_of' => is_string($result['population_as_of']) ? $result['population_as_of'] : null,
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
     * @return list<array{key: string|null, label: string|null, count: int, population: int|null}>
     */
    private function byMunicipality(Builder $query): array
    {
        $rows = (clone $query)->toBase()
            ->leftJoin('municipalities', 'municipalities.code', '=', 'medical_facilities.municipality_code')
            ->leftJoin('municipality_populations', 'municipality_populations.municipality_code', '=', 'medical_facilities.municipality_code')
            ->selectRaw('medical_facilities.municipality_code AS code, municipalities.name AS name, municipality_populations.population AS population, COUNT(*) AS facilities')
            ->groupBy('medical_facilities.municipality_code', 'municipalities.name', 'municipality_populations.population')
            ->orderByDesc('facilities')
            ->orderBy('code')
            ->get();

        // Both columns are NULL for facilities whose municipality couldn't be resolved.
        return array_values($rows->map(fn (object $row): array => [
            'key' => is_string($row->code) ? $row->code : null,
            'label' => is_string($row->name) ? $row->name : null,
            'count' => (int) $row->facilities,
            'population' => is_numeric($row->population) ? (int) $row->population : null,
        ])->all());
    }

    /**
     * Adds the population (only municipality groups carry one) and the count
     * per 10,000 people, so every grouping returns the same shape.
     *
     * @param  array{key: int|string|null, label: string|null, count: int, population?: int|null}  $group
     * @return array{key: int|string|null, label: string|null, count: int, population: int|null, count_per_10k: float|null}
     */
    private function withPerCapita(array $group): array
    {
        $population = $group['population'] ?? null;

        return [
            ...$group,
            'population' => $population,
            'count_per_10k' => $population ? round($group['count'] * 10_000 / $population, 2) : null,
        ];
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
