<?php

namespace App\Services\Mhlw\Sync;

/**
 * Reports what FacilityUpserter actually did for one row, so callers (and
 * a future Phase 4 import-run summary) don't have to infer it by diffing
 * events() after the fact.
 */
enum FacilityUpsertOutcome
{
    case Created;
    case Reopened;
    case Updated;
    case Unchanged;
}
