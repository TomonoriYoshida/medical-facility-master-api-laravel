<?php

namespace App\Http\Resources\Api\V1;

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
            'institution_type' => $this->institution_type->label(),
            'status' => $this->status->label(),
            'bureau_code' => $this->bureau_code->label(),
            'name' => $this->name,
            'prefecture_code' => $this->prefecture_code,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'phone_number' => $this->phone_number,
            'founder_name' => $this->founder_name,
            'administrator_name' => $this->administrator_name,
            'designated_on' => $this->designated_on?->toDateString(),
            'designation_history' => $this->designation_history,
            'bed_counts' => $this->bed_counts,
            'department_categories' => $this->department_categories
                ->map(fn ($category) => $category->label())
                ->values(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
