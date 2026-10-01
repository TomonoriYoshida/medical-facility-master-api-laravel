<?php

namespace App\Models;

use App\Enums\InstitutionType;
use Database\Factories\MedicalInfoNetLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_type', 'municipality_code', 'name_key', 'address_key', 'latitude', 'longitude', 'published_on'])]
class MedicalInfoNetLocation extends Model
{
    /** @use HasFactory<MedicalInfoNetLocationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'institution_type' => InstitutionType::class,
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'published_on' => 'date',
        ];
    }
}
