<?php

namespace App\Enums;

/**
 * 施設数の集計（GET /v1/stats/facilities）の `group_by` に指定できる単位
 */
enum FacilityStatsGrouping: string
{
    /** 指定年月日の月（期間内のすべての月を返す） */
    case Month = 'month';

    /** 市区町村 */
    case Municipality = 'municipality';

    /** 診療科目の大分類（複数の大分類を持つ施設は、それぞれに数える） */
    case DepartmentCategory = 'department_category';
}
