<?php

namespace App\Enums;

/**
 * What GET /api/v1/stats/facilities counts facilities by (`group_by`).
 */
enum FacilityStatsGrouping: string
{
    /** The month of designated_on; every month in the requested range is returned. */
    case Month = 'month';

    case Municipality = 'municipality';

    /** One facility counts once per department category it has. */
    case DepartmentCategory = 'department_category';
}
