<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
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
            /** 都道府県コード（2桁） */
            'prefecture_code' => ['sometimes', 'string', 'size:2'],

            /** 施設種別 */
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],

            /** 指定状態 */
            'status' => ['sometimes', Rule::enum(MedicalFacilityStatus::class)],

            /** 発行元の地方厚生局 */
            'bureau_code' => ['sometimes', Rule::enum(RhbBureau::class)],

            /** 診療科目の大分類 */
            'department_category' => ['sometimes', Rule::enum(DepartmentBaseCategory::class)],

            /** 施設名・住所のあいまい検索キーワード（全角半角・異体字ゆれを吸収） */
            'q' => ['sometimes', 'string', 'max:255'],

            /** 1ページあたりの件数（デフォルト25、最大100） */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
