<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\FacilityStatsGrouping;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\Prefecture;
use App\Http\Requests\Api\V1\Concerns\NamesParametersAsIs;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MedicalFacilityStatsRequest extends FormRequest
{
    use NamesParametersAsIs;

    /** Monthly buckets are returned for every month in the range, so it is capped. */
    public const int MAX_MONTHS = 60;

    /**
     * The validated `group_by`. Only call after validation has passed.
     */
    public function grouping(): FacilityStatsGrouping
    {
        return FacilityStatsGrouping::from($this->string('group_by')->toString());
    }

    /**
     * Whether the raw input asks for monthly buckets (usable before validation).
     */
    private function isMonthly(): bool
    {
        return $this->input('group_by') === FacilityStatsGrouping::Month->value;
    }

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
            /**
             * 集計の単位。`month`（指定年月日の月）、`municipality`（市区町村）、`department_category`（診療科目の大分類）。
             * `month` のときは `designated_from`・`designated_to` が必須で、期間は60か月まで
             */
            'group_by' => ['required', Rule::enum(FacilityStatsGrouping::class)],

            /** 都道府県コード（JIS X 0401の2桁、01〜47） */
            'prefecture_code' => ['sometimes', Rule::enum(Prefecture::class)],

            /** 市区町村コード（全国地方公共団体コード5桁。例: 13101 千代田区） */
            'municipality_code' => ['sometimes', 'string', 'regex:/^[0-9]{5}$/'],

            /** 施設種別 */
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],

            /** 指定状態 */
            'status' => ['sometimes', Rule::enum(MedicalFacilityStatus::class)],

            /** 診療科目の大分類 */
            'department_category' => ['sometimes', Rule::enum(DepartmentBaseCategory::class)],

            /**
             * 登録理由（`designation_history` の `reason`）。例: 新規、交代、組織変更、移転。
             * 指定年月日と組み合わせると「期間内に新規開業した施設」を数えられる
             */
            'designation_reason' => ['sometimes', 'string', 'encoding:UTF-8', 'max:20'],

            /** 指定年月日がこの日以降（YYYY-MM-DD）。`group_by=month` では必須 */
            // No `sometimes` here: it would skip requiredIf when the field is absent.
            'designated_from' => [
                Rule::requiredIf($this->isMonthly()),
                'date_format:Y-m-d',
            ],

            /** 指定年月日がこの日以前（YYYY-MM-DD）。`group_by=month` では必須 */
            'designated_to' => [
                Rule::requiredIf($this->isMonthly()),
                'date_format:Y-m-d',
                // Only compared when present: after_or_equal falls back to
                // parsing "designated_from" itself as a date otherwise.
                Rule::when($this->filled('designated_from'), 'after_or_equal:designated_from'),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isMonthly()
                    || $validator->errors()->hasAny(['group_by', 'designated_from', 'designated_to'])) {
                    return;
                }

                $from = Carbon::parse($this->string('designated_from')->toString())->startOfMonth();
                $to = Carbon::parse($this->string('designated_to')->toString())->startOfMonth();

                if ($from->diffInMonths($to) + 1 > self::MAX_MONTHS) {
                    $validator->errors()->add('designated_to', '期間は'.self::MAX_MONTHS.'か月以内にしてください。');
                }
            },
        ];
    }
}
