<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\Prefecture;
use App\Enums\RhbBureau;
use App\Enums\RhbCategory;
use App\Http\Controllers\Controller;
use App\Services\Rhb\RhbScope;
use Illuminate\Http\JsonResponse;

class OptionController extends Controller
{
    /**
     * designation_history reasons are free text in the source data, so there
     * is no closed set; these are the ones that cover nearly every facility
     * (most frequent first), for use as suggestions.
     */
    private const array COMMON_DESIGNATION_REASONS = ['新規', '組織変更', '交代', '移動', '移転', 'その他', '継承'];

    /**
     * 絞り込みの選択肢
     *
     * 一覧APIの絞り込み条件に使える値と、その日本語名を返します。`code` はそのまま各パラメータに渡せます。
     * 都道府県・地方厚生局・施設種別は、このAPIが取り扱う範囲のものだけを返します。
     * `designation_reasons` は元データでは自由記述のため、代表的な値のみです。
     */
    public function __invoke(RhbScope $scope): JsonResponse
    {
        $bureauByPrefectureCode = [];

        foreach ($scope->bureaus() as $meta) {
            foreach ($meta['prefecture_codes'] as $prefectureCode) {
                $bureauByPrefectureCode[$prefectureCode] = $meta['bureau'];
            }
        }

        // Pharmacy lists carry no departments.
        $hasDepartments = $scope->includesCategory(RhbCategory::Medical) || $scope->includesCategory(RhbCategory::Dental);

        return response()->json([
            'data' => [
                /** @var list<array{code: string, label: string, bureau: array{code: int, label: string}}> */
                'prefectures' => array_map(fn (Prefecture $prefecture): array => [
                    ...$this->codeAndLabel($prefecture),
                    'bureau' => $this->codeAndLabel($bureauByPrefectureCode[$prefecture->value]),
                ], $scope->prefectures()),
                /** @var list<array{code: int, label: string}> */
                'institution_types' => array_map($this->codeAndLabel(...), $scope->institutionTypes()),
                /** @var list<array{code: int, label: string}> */
                'statuses' => array_map($this->codeAndLabel(...), MedicalFacilityStatus::cases()),
                /** @var list<array{code: int, label: string}> */
                'bureaus' => array_map(fn (array $meta): array => $this->codeAndLabel($meta['bureau']), array_values($scope->bureaus())),
                /** @var list<array{code: int, label: string}> */
                'department_categories' => $hasDepartments ? array_map($this->codeAndLabel(...), DepartmentBaseCategory::cases()) : [],
                /** @var list<array{code: int, label: string}> */
                'event_types' => array_map($this->codeAndLabel(...), MedicalFacilityEventType::cases()),
                /** @var list<array{code: int, label: string}> */
                'geocode_levels' => array_map($this->codeAndLabel(...), GeocodeLevel::cases()),
                /**
                 * 代表的な登録理由（元データは自由記述）
                 *
                 * @var list<string>
                 */
                'designation_reasons' => self::COMMON_DESIGNATION_REASONS,
            ],
        ]);
    }

    /**
     * @return array{code: int|string, label: string}
     */
    private function codeAndLabel(
        Prefecture|InstitutionType|MedicalFacilityStatus|RhbBureau|DepartmentBaseCategory|MedicalFacilityEventType|GeocodeLevel $enum,
    ): array {
        return [
            'code' => $enum->value,
            'label' => $enum->label(),
        ];
    }
}
