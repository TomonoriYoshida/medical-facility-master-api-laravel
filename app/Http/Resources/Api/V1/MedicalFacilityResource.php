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
            'id' => $this->id,
            'facility_code' => $this->facility_code,
            /** 全国で一意な10桁の医療機関コード（都道府県番号＋点数表番号＋医療機関コード7桁） */
            'medical_institution_code' => $this->medical_institution_code,
            /**
             * 施設種別
             *
             * @var array{code: int, label: string}
             */
            'institution_type' => $this->codeAndLabel($this->institution_type),
            /**
             * 指定状態
             *
             * @var array{code: int, label: string}
             */
            'status' => $this->codeAndLabel($this->status),
            /**
             * 発行元の地方厚生局
             *
             * @var array{code: int, label: string}
             */
            'bureau' => $this->codeAndLabel($this->bureau_code),
            'name' => $this->name,
            'prefecture_code' => $this->prefecture_code,
            /** @var array{code: string, label: string} */
            'prefecture' => $this->codeAndLabel(Prefecture::from($this->prefecture_code)),
            /**
             * 住所から判定した市区町村。判定できない場合はnull
             *
             * @var array{code: string, label: string|null}|null
             */
            'municipality' => $this->municipality(),
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            /**
             * 住所から求めた座標（世界測地系）。`level` はその精度（住居・街区・地番・町丁目など）か、厚生労働省「医療情報ネット」の座標を使ったこと。
             * 求められなかった施設はnull
             *
             * @var array{latitude: float, longitude: float, level: array{code: int, label: string}}|null
             */
            'location' => $this->latitude === null || $this->longitude === null || $this->geocode_level === null ? null : [
                'latitude' => (float) $this->latitude,
                'longitude' => (float) $this->longitude,
                'level' => $this->codeAndLabel($this->geocode_level),
            ],
            /** 検索地点からの距離（メートル）。`latitude`・`longitude` を指定した検索のときだけ含まれる */
            'distance' => $this->when($this->resource->getAttribute('distance') !== null, fn (): int => (int) round((float) $this->resource->getAttribute('distance'))),
            'phone_number' => $this->phone_number,
            'designated_on' => $this->designated_on?->toDateString(),
            /**
             * 指定年月日欄の履歴（新しい順）。`reason` は登録理由（新規・交代・組織変更など、記載がなければnull）、
             * `date` は現在の指定期間の開始日と見られる日付（読み取れなければnull）
             *
             * @var list<array{reason: string|null, date: string|null}>|null
             */
            'designation_history' => $this->designation_history,
            /**
             * 病床種別（一般・療養・精神など）ごとの病床数。薬局は常にnull
             *
             * @var array<string, int>|null
             */
            'bed_counts' => $this->bed_counts,
            /**
             * 診療科目の大分類
             *
             * @var list<array{code: int, label: string}>
             */
            'department_categories' => $this->department_categories
                ?->map(fn (DepartmentBaseCategory $category): array => $this->codeAndLabel($category))
                ->values() ?? [],
            'created_at' => $this->created_at,
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
