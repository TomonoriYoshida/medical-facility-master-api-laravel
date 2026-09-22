<?php

namespace App\Models;

use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use Database\Factories\MedicalFacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_id',
    'institution_type',
    'status',
    'name',
    'name_kana',
    'short_name',
    'short_name_kana',
    'name_en',
    'prefecture_code',
    'city_code',
    'address',
    'latitude',
    'longitude',
    'website_url',
    'closure_schedule',
    'business_hours',
    'general_beds',
    'sanatorium_beds',
    'sanatorium_beds_medical_insurance',
    'sanatorium_beds_care_insurance',
    'psychiatric_beds',
    'tuberculosis_beds',
    'infectious_disease_beds',
    'total_beds',
])]
class MedicalFacility extends Model
{
    /** @use HasFactory<MedicalFacilityFactory> */
    use HasFactory;

    /**
     * @return HasMany<MedicalFacilityDepartment, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(MedicalFacilityDepartment::class);
    }

    /**
     * @return HasMany<MedicalFacilityEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MedicalFacilityEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'institution_type' => InstitutionType::class,
            'status' => MedicalFacilityStatus::class,
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'closure_schedule' => 'array',
            'business_hours' => 'array',
        ];
    }
}
