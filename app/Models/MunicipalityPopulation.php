<?php

namespace App\Models;

use Database\Factories\MunicipalityPopulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['municipality_code', 'population', 'as_of'])]
class MunicipalityPopulation extends Model
{
    /** @use HasFactory<MunicipalityPopulationFactory> */
    use HasFactory;

    protected $primaryKey = 'municipality_code';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'as_of' => 'date',
        ];
    }
}
