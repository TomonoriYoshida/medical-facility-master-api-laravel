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
            'institution_type' => $this->codeAndLabel($this->institution_type),
            'status' => $this->codeAndLabel($this->status),
            'bureau' => $this->codeAndLabel($this->bureau_code),
            'name' => $this->name,
            'prefecture_code' => $this->prefecture_code,
            'prefecture' => $this->codeAndLabel(Prefecture::from($this->prefecture_code)),
            /** 住所から判定した市区町村。判定できない場合はnull */
            'municipality' => $this->municipality_code === null ? null : [
                'code' => $this->municipality_code,
                'label' => app(MunicipalityResolver::class)->label($this->municipality_code),
            ],
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            /**
             * 住所から求めた座標（世界測地系）。`level` はその精度（住居・街区・地番・町丁目など）か、厚生労働省「医療情報ネット」の座標を使ったこと。
             * 求められなかった施設はnull
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
            'designation_history' => $this->designation_history,
            'bed_counts' => $this->bed_counts,
            'department_categories' => $this->department_categories
                ?->map(fn (DepartmentBaseCategory $category): array => $this->codeAndLabel($category))
                ->values() ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
