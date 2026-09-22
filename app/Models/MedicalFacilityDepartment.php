<?php

namespace App\Models;

use Database\Factories\MedicalFacilityDepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medical_facility_id',
    'department_code',
    'department_name',
    'consultation_hours',
    'reception_hours',
    'last_seen_mhlw_dataset_download_id',
])]
class MedicalFacilityDepartment extends Model
{
    /** @use HasFactory<MedicalFacilityDepartmentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MedicalFacility, $this>
     */
    public function medicalFacility(): BelongsTo
    {
        return $this->belongsTo(MedicalFacility::class);
    }

    /**
     * @return BelongsTo<MhlwDatasetDownload, $this>
     */
    public function lastSeenMhlwDatasetDownload(): BelongsTo
    {
        return $this->belongsTo(MhlwDatasetDownload::class, 'last_seen_mhlw_dataset_download_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consultation_hours' => 'array',
            'reception_hours' => 'array',
        ];
    }
}
