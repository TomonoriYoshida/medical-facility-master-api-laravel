<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NamesParametersAsIs;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicHolidayIndexRequest extends FormRequest
{
    use NamesParametersAsIs;

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
            /** この日以降の祝日（YYYY-MM-DD）。省略時は今年の1月1日 */
            'from' => ['sometimes', 'date_format:Y-m-d'],

            /** この日以前の祝日（YYYY-MM-DD）。省略時は来年の12月31日 */
            'to' => [
                'sometimes',
                'date_format:Y-m-d',
                // Only compared when present: after_or_equal falls back to
                // parsing "from" itself as a date otherwise.
                Rule::when($this->filled('from'), 'after_or_equal:from'),
            ],
        ];
    }
}
