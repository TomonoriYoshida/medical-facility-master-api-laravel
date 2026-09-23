<?php

namespace App\Models;

use App\Enums\DepartmentBaseCategory;
use App\Enums\InstitutionType;
use App\Enums\MedicalFacilityStatus;
use App\Enums\RhbBureau;
use Database\Factories\MedicalFacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'facility_code',
    'bureau_code',
    'institution_type',
    'status',
    'last_seen_rhb_dataset_download_id',
    'name',
    'prefecture_code',
    'postal_code',
    'address',
    'latitude',
    'longitude',
    'phone_number',
    'founder_name',
    'administrator_name',
    'designated_on',
    'designation_history',
    'bed_counts',
    'department_categories',
])]
class MedicalFacility extends Model
{
    /** @use HasFactory<MedicalFacilityFactory> */
    use HasFactory;

    /**
     * @return HasMany<MedicalFacilityEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MedicalFacilityEvent::class);
    }

    /**
     * @return BelongsTo<RhbDatasetDownload, $this>
     */
    public function lastSeenRhbDatasetDownload(): BelongsTo
    {
        return $this->belongsTo(RhbDatasetDownload::class, 'last_seen_rhb_dataset_download_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bureau_code' => RhbBureau::class,
            'institution_type' => InstitutionType::class,
            'status' => MedicalFacilityStatus::class,
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'designated_on' => 'date',
            'designation_history' => 'array',
            'bed_counts' => 'array',
            'department_categories' => AsEnumCollection::class.':'.DepartmentBaseCategory::class,
        ];
    }
}
