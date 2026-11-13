<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Prefecture;
use App\Http\Requests\Api\V1\Concerns\NamesParametersAsIs;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RailwayStationIndexRequest extends FormRequest
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
            /**
             * 駅名（例: `春日`、`市ヶ谷駅`）。末尾の「駅」はあってもなくてもよく、全角半角・「ヶ」「ケ」の違いは区別しない。
             * 一致する駅がなければ、この語で始まる駅を返す
             */
            'name' => ['required', 'string', 'encoding:UTF-8', 'max:50'],

            /** 都道府県コード（JIS X 0401の2桁、01〜47）。同じ名前の駅を絞り込む */
            'prefecture_code' => ['sometimes', Rule::enum(Prefecture::class)],
        ];
    }
}
