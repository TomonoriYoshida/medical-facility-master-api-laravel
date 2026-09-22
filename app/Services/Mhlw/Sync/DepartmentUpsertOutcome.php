<?php

namespace App\Services\Mhlw\Sync;

/**
 * Reports what DepartmentUpserter actually did for one row. No Reopened
 * case: departments have no status column and are hard-deleted on
 * removal, so "not found" always means genuinely new.
 */
enum DepartmentUpsertOutcome
{
    case Created;
    case Updated;
    case Unchanged;
}
