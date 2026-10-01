<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\Prefecture;
use App\Enums\RhbBureau;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MedicalFacilityIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** 都道府県コード（JIS X 0401の2桁、01〜47） */
            'prefecture_code' => ['sometimes', Rule::enum(Prefecture::class)],

            /** 市区町村コード（全国地方公共団体コード5桁。例: 13101 千代田区）。住所から判定した値で、判定できない施設は含まれない */
            'municipality_code' => ['sometimes', 'string', 'regex:/^[0-9]{5}$/'],

            /** 施設種別 */
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],

            /** 指定状態 */
            'status' => ['sometimes', Rule::enum(MedicalFacilityStatus::class)],

            /** 発行元の地方厚生局 */
            'bureau_code' => ['sometimes', Rule::enum(RhbBureau::class)],

            /** 診療科目の大分類 */
            'department_category' => ['sometimes', Rule::enum(DepartmentBaseCategory::class)],

            /**
             * 10桁の医療機関コード（都道府県番号＋点数表番号＋医療機関コード7桁）。
             * カンマ区切りで最大100件まで指定できる
             */
            'medical_institution_code' => ['sometimes', 'string', 'regex:/^[0-9]{10}(,[0-9]{10}){0,99}$/'],

            /** 施設名・住所のあいまい検索キーワード（全角半角・異体字ゆれを吸収） */
            'q' => ['sometimes', 'string', 'encoding:UTF-8', 'max:255'],

            /** 指定年月日がこの日以降（YYYY-MM-DD） */
            'designated_from' => ['sometimes', 'date_format:Y-m-d'],

            /** 指定年月日がこの日以前（YYYY-MM-DD） */
            'designated_to' => [
                'sometimes',
                'date_format:Y-m-d',
                // Only compared when present: after_or_equal falls back to
                // parsing "designated_from" itself as a date otherwise.
                Rule::when($this->filled('designated_from'), 'after_or_equal:designated_from'),
            ],

            /**
             * 登録理由（`designation_history` の `reason`）。例: 新規、交代、組織変更、移転。
             * 指定年月日と組み合わせると「期間内に新規開業した施設」を取得できる
             */
            'designation_reason' => ['sometimes', 'string', 'encoding:UTF-8', 'max:20'],

            /**
             * 並び順。`designated_on` / `-designated_on`（指定年月日の古い順 / 新しい順、指定年月日のない施設は末尾）、
             * `updated_at` / `-updated_at`（内容が変わった日時の古い順 / 新しい順）。省略時はid順
             */
            'sort' => ['sometimes', Rule::in(['id', 'designated_on', '-designated_on', 'updated_at', '-updated_at'])],

            /**
             * この日時以降に内容が変わった施設だけを返す（ISO 8601、例: 2026-10-01T05:00:00Z）。
             * 廃止・再開も含む。差分の同期には `sort=updated_at` と組み合わせる
             */
            'updated_since' => ['sometimes', 'date'],

            /**
             * 検索地点の緯度（世界測地系）。`longitude` と組み合わせ、`radius` 以内の施設を近い順に返す
             * （`sort` を指定した場合はその順）。各施設に `distance` が付く
             */
            'latitude' => ['required_with:longitude', 'numeric', 'between:20,46'],

            /** 検索地点の経度（世界測地系） */
            'longitude' => ['required_with:latitude', 'numeric', 'between:122,154'],

            /** 検索半径（メートル、デフォルト1000、最大20000）。`latitude`・`longitude` と組み合わせる */
            'radius' => ['sometimes', 'integer', 'min:1', 'max:20000'],

            /** 1ページあたりの件数（デフォルト25、最大100） */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
