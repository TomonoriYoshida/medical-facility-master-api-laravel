<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\GeocodeLevel;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\Prefecture;
use App\Enums\RhbBureau;
use App\Models\MedicalFacility;
use App\Services\Address\MunicipalityResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MedicalFacility
 */
class MedicalFacilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * 施設のID。施設詳細・履歴・診療時間のAPIのパスに使う
             *
             * @example 33961
             */
            'id' => $this->id,
            /**
             * 地方厚生局が付けた7桁の医療機関コード。同じ都道府県・施設種別の中でだけ一意
             *
             * @example 0114611
             */
            'facility_code' => $this->facility_code,
            /**
             * 全国で一意な10桁の医療機関コード（都道府県番号2桁＋点数表番号1桁＋`facility_code`）。
             * 点数表番号は病院・診療所が1、歯科診療所が3、薬局が4。レセプトなどで使われる番号
             *
             * @example 1310114611
             */
            'medical_institution_code' => $this->medical_institution_code,
            /**
             * 施設種別。`code` は一覧APIの `institution_type` に渡せる
             *
             * @var array{code: int, label: string}
             *
             * @example {"code": 1, "label": "病院"}
             */
            'institution_type' => $this->codeAndLabel($this->institution_type),
            /**
             * 指定状態。`code` は一覧APIの `status` に渡せる
             *
             * @var array{code: int, label: string}
             *
             * @example {"code": 1, "label": "指定中"}
             */
            'status' => $this->codeAndLabel($this->status),
            /**
             * 指定一覧を公開している地方厚生局。`code` は一覧APIの `bureau_code` に渡せる
             *
             * @var array{code: int, label: string}
             *
             * @example {"code": 3, "label": "関東信越厚生局"}
             */
            'bureau' => $this->codeAndLabel($this->bureau_code),
            /**
             * 施設名（元データの表記のまま。法人名を含むことがある）
             *
             * @example 東京歯科大学水道橋病院
             */
            'name' => $this->name,
            /**
             * 都道府県コード（JIS X 0401の2桁）。`prefecture.code` と同じ値
             *
             * @example 13
             */
            'prefecture_code' => $this->prefecture_code,
            /**
             * 都道府県。`code` は一覧APIの `prefecture_code` に渡せる
             *
             * @var array{code: string, label: string}
             *
             * @example {"code": "13", "label": "東京都"}
             */
            'prefecture' => $this->codeAndLabel(Prefecture::from($this->prefecture_code)),
            /**
             * 住所から判定した市区町村。`code` は全国地方公共団体コード5桁で、一覧APIの `municipality_code` に渡せる。
             * 判定できない場合はnull
             *
             * @var array{code: string, label: string|null}|null
             *
             * @example {"code": "13101", "label": "千代田区"}
             */
            'municipality' => $this->municipality(),
            /**
             * 郵便番号（NNN-NNNN）
             *
             * @example 101-0061
             */
            'postal_code' => $this->postal_code,
            /**
             * 所在地（元データの表記のまま。都道府県名を含むかは地方厚生局によって異なる）
             *
             * @example 千代田区神田三崎町二丁目９番１８号
             */
            'address' => $this->address,
            /**
             * 住所から求めた座標（世界測地系）。`level` はその精度（住居・街区・地番・町丁目など）か、厚生労働省「医療情報ネット」の座標を使ったこと。
             * 求められなかった施設はnull
             *
             * @var array{latitude: float, longitude: float, level: array{code: int, label: string}}|null
             *
             * @example {"latitude": 35.701308, "longitude": 139.754859, "level": {"code": 2, "label": "街区"}}
             */
            'location' => $this->latitude === null || $this->longitude === null || $this->geocode_level === null ? null : [
                'latitude' => (float) $this->latitude,
                'longitude' => (float) $this->longitude,
                'level' => $this->codeAndLabel($this->geocode_level),
            ],
            /**
             * 検索地点からの距離（メートル）。`latitude`・`longitude` を指定した検索のときだけ含まれる
             *
             * @example 350
             */
            'distance' => $this->when($this->resource->getAttribute('distance') !== null, fn (): int => (int) round((float) $this->resource->getAttribute('distance'))),
            /**
             * 電話番号（区切りをハイフンに揃えたもの）
             *
             * @example 03-3262-3421
             */
            'phone_number' => $this->phone_number,
            /**
             * 指定年月日（YYYY-MM-DD）。最初に指定された日で、6年ごとの指定の更新では変わらない
             *
             * @example 1991-11-01
             */
            'designated_on' => $this->designated_on?->toDateString(),
            /**
             * 指定年月日欄の履歴（新しい順）。`reason` は登録理由（新規・交代・組織変更など、記載がなければnull）、
             * `date` は現在の指定期間の開始日と見られる日付（読み取れなければnull）
             *
             * @var list<array{reason: string|null, date: string|null}>|null
             *
             * @example [{"reason": null, "date": "2024-11-01"}]
             */
            'designation_history' => $this->designation_history,
            /**
             * 病床種別（一般・療養・精神など）ごとの病床数。病床のない施設と薬局はnull
             *
             * @var array<string, int>|null
             *
             * @example {"一般": 20}
             */
            'bed_counts' => $this->bed_counts,
            /**
             * 診療科目の大分類。`code` は一覧APIの `department_category` に渡せる。薬局は空の配列
             *
             * @var list<array{code: int, label: string}>
             *
             * @example [{"code": 1, "label": "内科"}, {"code": 5, "label": "眼科"}, {"code": 15, "label": "歯科"}]
             */
            'department_categories' => $this->department_categories
                ?->map(fn (DepartmentBaseCategory $category): array => $this->codeAndLabel($category))
                ->values() ?? [],
            /**
             * このAPIに施設を初めて取り込んだ日時（開業日ではない）
             *
             * @example 2026-09-23T20:15:48.000000Z
             */
            'created_at' => $this->created_at,
            /**
             * 施設の情報（座標を含む）が最後に変わった日時。差分の同期には一覧APIの `updated_since` を使う
             *
             * @example 2026-10-01T13:56:08.000000Z
             */
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * `label` is null only while the municipalities table has not been
     * seeded (MunicipalitySeeder).
     *
     * @return array{code: string, label: string|null}|null
     */
    private function municipality(): ?array
    {
        if ($this->municipality_code === null) {
            return null;
        }

        return [
            'code' => $this->municipality_code,
            'label' => app(MunicipalityResolver::class)->label($this->municipality_code),
        ];
    }

    /**
     * `code` is exactly what the list endpoint's corresponding filter
     * (institution_type / status / bureau_code / department_category)
     * accepts, so a client can feed it straight back as a query parameter;
     * `label` is the Japanese display name.
     *
     * @return array{code: int|string, label: string}
     */
    private function codeAndLabel(InstitutionType|MedicalFacilityStatus|RhbBureau|DepartmentBaseCategory|Prefecture|GeocodeLevel $enum): array
    {
        return [
            'code' => $enum->value,
            'label' => $enum->label(),
        ];
    }
}
