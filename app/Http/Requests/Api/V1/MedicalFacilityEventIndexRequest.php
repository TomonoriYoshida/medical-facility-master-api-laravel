<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityEventType;
use App\Enums\Prefecture;
use App\Http\Requests\Api\V1\Concerns\LimitsPageDepth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MedicalFacilityEventIndexRequest extends FormRequest
{
    use LimitsPageDepth;

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
            /** 変化の種類（1: 新規 / 2: 廃止 / 3: 変更） */
            'event_type' => ['sometimes', Rule::enum(MedicalFacilityEventType::class)],

            /** 変化が載った公開データの日付がこの日以降（YYYY-MM-DD） */
            'occurred_from' => ['sometimes', 'date_format:Y-m-d'],

            /** 変化が載った公開データの日付がこの日以前（YYYY-MM-DD） */
            'occurred_to' => [
                'sometimes',
                'date_format:Y-m-d',
                // Only compared when present: after_or_equal falls back to
                // parsing "occurred_from" itself as a date otherwise.
                Rule::when($this->filled('occurred_from'), 'after_or_equal:occurred_from'),
            ],

            /**
             * この日時以降に検知された変化だけを返す（ISO 8601、例: 2026-10-01T05:00:00+09:00）。
             * `occurred_on` は公開データの日付で、取り込んで検知するのはその数日〜数週間後のため、
             * 「前回確認してから増えた変化」はこちらで絞り込む
             */
            'detected_since' => ['sometimes', 'date'],

            /** 施設の都道府県コード（JIS X 0401の2桁、01〜47） */
            'prefecture_code' => ['sometimes', Rule::enum(Prefecture::class)],

            /** 施設の種別 */
            'institution_type' => ['sometimes', Rule::enum(InstitutionType::class)],

            /** 1ページあたりの件数（デフォルト25、最大100） */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],

            ...$this->pageRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->pageMessages('それより先は、期間（occurred_from / occurred_to、detected_since）などの条件で絞り込んでください。');
    }
}
