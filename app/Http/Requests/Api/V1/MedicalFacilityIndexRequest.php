<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\Prefecture;
use App\Enums\RhbBureau;
use App\Http\Requests\Api\V1\Concerns\LimitsPageDepth;
use App\Http\Requests\Api\V1\Concerns\NamesParametersAsIs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MedicalFacilityIndexRequest extends FormRequest
{
    use LimitsPageDepth;
    use NamesParametersAsIs;

    /** Each word adds a LIKE over name and address, so their number is capped. */
    public const int MAX_SEARCH_WORDS = 5;

    /**
     * The words of a `q` search: split on half- and full-width spaces.
     *
     * @return list<string>
     */
    public static function searchWords(string $term): array
    {
        return preg_split('/[\s\x{3000}]+/u', $term, flags: PREG_SPLIT_NO_EMPTY) ?: [];
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

            /**
             * 施設名・住所のあいまい検索キーワード（全角半角・異体字ゆれを吸収）。
             * 空白（全角・半角）で区切ると、すべての語を含む施設を返す（例: `札幌 眼科`、最大5語）
             */
            'q' => [
                'sometimes',
                'string',
                'encoding:UTF-8',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && count(self::searchWords($value)) > self::MAX_SEARCH_WORDS) {
                        $fail(':attribute の語は'.self::MAX_SEARCH_WORDS.'語以内にしてください。');
                    }
                },
            ],

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
             * この日時に受付中の施設だけを返す（ISO 8601。時差の指定がなければ日本時間。例: `2026-10-05T10:30`）。
             * 厚生労働省「医療情報ネット」の診療時間で判定し（受付時間があれば受付時間、なければ診療時間。どれかの診療科が開いていれば対象）、
             * 祝日は「祝」の時刻、「第2水曜休診」のような休みも反映する。照合できない施設や、年末年始などの臨時の休みは判定できない
             */
            'open_at' => ['sometimes', 'date'],

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

            ...$this->pageRules(),

            /**
             * `cursor` にすると、ページ番号の代わりにカーソルでページを送る（件数の上限なし）。
             * 次のページは `links.next`（または `meta.next_cursor` を `cursor` に渡す）で取得する。
             * 全件の取得や差分の同期向け。並び順は id 順か `sort=updated_at` のときだけ使える
             */
            'pagination' => ['sometimes', Rule::in(['cursor'])],

            /** カーソル方式の次のページの位置（`meta.next_cursor` の値） */
            'cursor' => ['sometimes', 'string', 'max:1000'],

            /**
             * `capped` にすると、件数（`meta.total`）を最初の1万件を超えた時点で数え終えて速く返す。
             * 超えたときは `meta.total_is_capped` が true で、`meta.total` は 10,001（実際の件数はそれ以上）。
             * ページ番号で移動できる範囲（`meta.max_page`）は変わらない。件数を「1万件以上」と表示できる画面向け
             */
            'total' => ['sometimes', Rule::in(['capped'])],
        ];
    }

    public function usesCursor(): bool
    {
        return $this->validated('pagination') === 'cursor';
    }

    /**
     * The row count at which counting stops, one past the rows page numbers
     * reach, or null to count every row.
     */
    public function totalCap(): ?int
    {
        return $this->validated('total') === 'capped' ? config()->integer('api.max_paginated_rows') + 1 : null;
    }

    /**
     * Cursor pagination resumes after the last row's values of the ordering
     * columns, so they must be non-null and unique together: id, or
     * updated_at then id. designated_on can be null, and a nearby search's
     * distance is computed rather than a column.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('pagination') !== 'cursor') {
                    return;
                }

                $sortsByColumn = in_array($this->input('sort'), [null, 'id', 'updated_at'], true);
                $searchesNearby = $this->filled('latitude') || $this->filled('longitude');

                if (! $sortsByColumn || $searchesNearby) {
                    $validator->errors()->add(
                        'pagination',
                        'カーソル方式（pagination=cursor）は、id 順か sort=updated_at のときだけ使えます（近隣検索の距離順、指定年月日順、新しい順は不可）。',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->pageMessages('それより先は、条件を絞り込むか、全件の一括ダウンロード（/api/v1/exports）か、カーソル方式（pagination=cursor）を使ってください。');
    }
}
