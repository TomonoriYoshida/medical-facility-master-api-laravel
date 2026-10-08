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
                /**
                 * 都道府県と、その施設の指定一覧を公開している地方厚生局。`code` は `prefecture_code` に渡せる
                 *
                 * @var list<array{code: string, label: string, bureau: array{code: int, label: string}}>
                 *
                 * @example [{"code": "01", "label": "北海道", "bureau": {"code": 1, "label": "北海道厚生局"}}, {"code": "02", "label": "青森県", "bureau": {"code": 2, "label": "東北厚生局"}}]
                 */
                'prefectures' => array_map(fn (Prefecture $prefecture): array => [
                    ...$this->codeAndLabel($prefecture),
                    'bureau' => $this->codeAndLabel($bureauByPrefectureCode[$prefecture->value]),
                ], $scope->prefectures()),
                /**
                 * 施設種別。`code` は `institution_type` に渡せる
                 *
                 * @var list<array{code: int, label: string}>
                 *
                 * @example [{"code": 1, "label": "病院"}, {"code": 2, "label": "診療所"}, {"code": 3, "label": "歯科診療所"}, {"code": 4, "label": "薬局"}]
                 */
                'institution_types' => array_map($this->codeAndLabel(...), $scope->institutionTypes()),
                /**
                 * 指定状態。`code` は `status` に渡せる
                 *
                 * @var list<array{code: int, label: string}>
                 *
                 * @example [{"code": 1, "label": "指定中"}, {"code": 2, "label": "廃止"}, {"code": 3, "label": "休止"}]
                 */
                'statuses' => array_map($this->codeAndLabel(...), MedicalFacilityStatus::cases()),
                /**
                 * 地方厚生局。`code` は `bureau_code` に渡せる
                 *
                 * @var list<array{code: int, label: string}>
                 *
                 * @example [{"code": 1, "label": "北海道厚生局"}, {"code": 2, "label": "東北厚生局"}]
                 */
                'bureaus' => array_map(fn (array $meta): array => $this->codeAndLabel($meta['bureau']), array_values($scope->bureaus())),
                /**
                 * 診療科目の大分類。`code` は `department_category` に渡せる。薬局だけを取り扱うときは空の配列
                 *
                 * @var list<array{code: int, label: string}>
                 *
                 * @example [{"code": 1, "label": "内科"}, {"code": 2, "label": "外科"}, {"code": 3, "label": "小児科"}]
                 */
                'department_categories' => $hasDepartments ? array_map($this->codeAndLabel(...), DepartmentBaseCategory::cases()) : [],
                /**
                 * 変化の種類。`code` は変化の一覧の `event_type` に渡せる
                 *
                 * @var list<array{code: int, label: string}>
                 *
                 * @example [{"code": 1, "label": "新規"}, {"code": 2, "label": "廃止"}, {"code": 3, "label": "変更"}]
                 */
                'event_types' => array_map($this->codeAndLabel(...), MedicalFacilityEventType::cases()),
                /**
                 * 座標の精度（施設の `location.level`）
                 *
                 * @var list<array{code: int, label: string}>
                 *
                 * @example [{"code": 1, "label": "住居"}, {"code": 2, "label": "街区"}, {"code": 3, "label": "地番"}]
                 */
                'geocode_levels' => array_map($this->codeAndLabel(...), GeocodeLevel::cases()),
                /**
                 * 代表的な登録理由（元データは自由記述）。`designation_reason` に渡せる
                 *
                 * @var list<string>
                 *
                 * @example ["新規", "組織変更", "交代", "移動", "移転", "その他", "継承"]
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
