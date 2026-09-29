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

            /** 施設種別 */
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],

            /** 指定状態 */
            'status' => ['sometimes', Rule::enum(MedicalFacilityStatus::class)],

            /** 発行元の地方厚生局 */
            'bureau_code' => ['sometimes', Rule::enum(RhbBureau::class)],

            /** 診療科目の大分類 */
            'department_category' => ['sometimes', Rule::enum(DepartmentBaseCategory::class)],

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

            /** 並び順。`designated_on`（指定年月日の古い順）、`-designated_on`（新しい順、指定年月日のない施設は末尾）。省略時はid順 */
            'sort' => ['sometimes', Rule::in(['id', 'designated_on', '-designated_on'])],

            /** 1ページあたりの件数（デフォルト25、最大100） */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
