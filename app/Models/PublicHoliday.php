<?php

namespace App\Models;

use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['date', 'name'])]
class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    /**
     * Whether the date (in Japan) is a public holiday.
     */
    public static function isHoliday(Carbon $date): bool
    {
        return self::query()->whereDate('date', $date->toDateString())->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
