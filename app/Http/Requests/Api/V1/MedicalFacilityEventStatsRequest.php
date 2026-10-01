<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\FacilityStatsGrouping;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\Prefecture;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MedicalFacilityEventStatsRequest extends FormRequest
{
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
             * 集計の単位。`month`（変化が載った公開データの月）、`municipality`（施設の市区町村）。
             * `month` のときは `occurred_from`・`occurred_to` が必須で、期間は60か月まで
             */
            'group_by' => [
                'required',
                Rule::enum(FacilityStatsGrouping::class)->only([FacilityStatsGrouping::Month, FacilityStatsGrouping::Municipality]),
            ],

            /** 変化の種類（1: 新規 / 2: 廃止） */
            'event_type' => [
                'required',
                Rule::enum(MedicalFacilityEventType::class)->only([MedicalFacilityEventType::Created, MedicalFacilityEventType::Removed]),
            ],

            // No `sometimes` on these two: it would skip requiredIf when the field is absent.
            /** 変化が載った公開データの日付がこの日以降（YYYY-MM-DD）。`group_by=month` では必須 */
            'occurred_from' => [
                Rule::requiredIf($this->isMonthly()),
                'date_format:Y-m-d',
            ],

            /** 変化が載った公開データの日付がこの日以前（YYYY-MM-DD）。`group_by=month` では必須 */
            'occurred_to' => [
                Rule::requiredIf($this->isMonthly()),
                'date_format:Y-m-d',
                // Only compared when present: after_or_equal falls back to
                // parsing "occurred_from" itself as a date otherwise.
                Rule::when($this->filled('occurred_from'), 'after_or_equal:occurred_from'),
            ],

            /** 施設の都道府県コード（JIS X 0401の2桁、01〜47） */
            'prefecture_code' => ['sometimes', Rule::enum(Prefecture::class)],

            /** 施設の市区町村コード（全国地方公共団体コード5桁） */
            'municipality_code' => ['sometimes', 'string', 'regex:/^[0-9]{5}$/'],

            /** 施設の種別 */
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],

            /** 施設の診療科目の大分類（現在の診療科目で判定） */
            'department_category' => ['sometimes', Rule::enum(DepartmentBaseCategory::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isMonthly() || $validator->errors()->hasAny(['group_by', 'occurred_from', 'occurred_to'])) {
                    return;
                }

                $from = Carbon::parse($this->string('occurred_from')->toString())->startOfMonth();
                $to = Carbon::parse($this->string('occurred_to')->toString())->startOfMonth();

                if ($from->diffInMonths($to) + 1 > self::MAX_MONTHS) {
                    $validator->errors()->add('occurred_to', 'The period must not exceed '.self::MAX_MONTHS.' months.');
                }
            },
        ];
    }

    /**
     * Whether the raw input asks for monthly buckets (usable before validation).
     */
    private function isMonthly(): bool
    {
        return $this->input('group_by') === FacilityStatsGrouping::Month->value;
    }
}
