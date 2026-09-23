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
            'prefecture_code' => ['sometimes', 'string', 'size:2'],
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],
            'status' => ['sometimes', Rule::enum(MedicalFacilityStatus::class)],
            'bureau_code' => ['sometimes', Rule::enum(RhbBureau::class)],
            'department_category' => ['sometimes', Rule::enum(DepartmentBaseCategory::class)],
            'q' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
