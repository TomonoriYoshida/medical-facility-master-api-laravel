<?php

namespace App\Models;

use App\Enums\InstitutionType;
use Database\Factories\MedicalInfoNetLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array{weekly: list<'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'>, monthly: list<array{week: int, day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'}>, holidays: bool|null, other: string|null}|null $closures as MedicalInfoNetHours::closures() builds them
 */
#[Fillable(['source_id', 'institution_type', 'municipality_code', 'name_key', 'address_key', 'latitude', 'longitude', 'closures', 'published_on'])]
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
            'closures' => 'array',
            'published_on' => 'date',
        ];
    }

    /**
     * The facility's opening hours, when the 医療情報ネット lists any.
     *
     * @return HasOne<MedicalInfoNetSchedule, $this>
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(MedicalInfoNetSchedule::class, 'source_id', 'source_id');
    }
}
