<?php

namespace App\Http\Controllers\Api\V1\Concerns;

trait EscapesLikePatterns
{
    /**
     * Makes user input match literally inside a LIKE pattern. Backslash
     * must be escaped first, since it is MySQL's default LIKE escape
     * character and the other replacements introduce new backslashes.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
