<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\MedicalFacility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait FiltersMedicalFacilities
{
    /**
     * The attribute filters shared by the list and the stats endpoints.
     * Columns are qualified because the stats endpoint joins municipalities,
     * which has its own prefecture_code.
     *
     * @param  Builder<MedicalFacility>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<MedicalFacility>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $column = $query->qualifyColumn(...);

        return $query
            ->when($filters['medical_institution_code'] ?? null, fn ($query, $codes) => $query->whereIn($column('medical_institution_code'), explode(',', $codes)))
            ->when($filters['prefecture_code'] ?? null, fn ($query, $value) => $query->where($column('prefecture_code'), $value))
            ->when($filters['municipality_code'] ?? null, fn ($query, $value) => $query->where($column('municipality_code'), $value))
            ->when($filters['institution_type'] ?? null, fn ($query, $value) => $query->where($column('institution_type'), $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where($column('status'), $value))
            ->when($filters['bureau_code'] ?? null, fn ($query, $value) => $query->where($column('bureau_code'), $value))
            ->when($filters['department_category'] ?? null, fn ($query, $value) => $query->whereJsonContains($column('department_categories'), (int) $value))
            ->when($filters['designated_from'] ?? null, fn ($query, $date) => $query->where($column('designated_on'), '>=', $date))
            ->when($filters['designated_to'] ?? null, fn ($query, $date) => $query->where($column('designated_on'), '<=', $date))
            // updated_at is stored in UTC; the given offset, if any, is honored.
            ->when($filters['updated_since'] ?? null, fn ($query, $since) => $query->where($column('updated_at'), '>=', Carbon::parse($since)->utc()))
            ->when($filters['designation_reason'] ?? null, fn ($query, $reason) => $query->whereJsonContains($column('designation_history'), ['reason' => $reason]));
    }
}
