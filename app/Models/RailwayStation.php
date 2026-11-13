<?php

namespace App\Models;

use Database\Factories\RailwayStationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['station_code', 'group_code', 'name', 'name_normalized', 'line_name', 'operator_name', 'prefecture_code', 'municipality_code', 'latitude', 'longitude'])]
class RailwayStation extends Model
{
    /** @use HasFactory<RailwayStationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }
}
