<?php

namespace App\Models;

use Database\Factories\MedicalInfoNetScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{departments: list<string>, slots: list<array{number: int, days: list<array{day: 'mon'|'tue'|'wed'|'thu'|'fri'|'sat'|'sun'|'holiday', opens: string|null, closes: string|null, reception_opens: string|null, reception_closes: string|null}>}>}> $schedules as MedicalInfoNetHours builds them
 */
#[Fillable(['source_id', 'schedules'])]
class MedicalInfoNetSchedule extends Model
{
    /** @use HasFactory<MedicalInfoNetScheduleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedules' => 'array',
        ];
    }
}
