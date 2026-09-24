<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use App\Models\MedicalFacility;
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
            'institution_type' => $this->codeAndLabel($this->institution_type),
            'status' => $this->codeAndLabel($this->status),
            'bureau' => $this->codeAndLabel($this->bureau_code),
            'name' => $this->name,
            'prefecture_code' => $this->prefecture_code,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'phone_number' => $this->phone_number,
            'founder_name' => $this->founder_name,
            'administrator_name' => $this->administrator_name,
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
     * @return array{code: int, label: string}
     */
    private function codeAndLabel(InstitutionType|MedicalFacilityStatus|RhbBureau|DepartmentBaseCategory $enum): array
    {
        return [
            'code' => $enum->value,
            'label' => $enum->label(),
        ];
    }
}
