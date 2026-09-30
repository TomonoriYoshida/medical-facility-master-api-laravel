<?php

namespace App\Logging;

use Throwable;

/**
 * Where in the project an exception came from: the first frame outside
 * vendor/. Exceptions such as QueryException are all thrown from the same
 * framework line, which says nothing about which query failed.
 */
class ExceptionOrigin
{
    /**
     * @return array{file: string, line: int}
     */
    public static function of(Throwable $exception): array
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()], ...$exception->getTrace()];
        $vendor = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR;

        foreach ($frames as $frame) {
            if (isset($frame['file'], $frame['line']) && ! str_contains($frame['file'], $vendor)) {
                return ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return ['file' => $exception->getFile(), 'line' => $exception->getLine()];
    }
}
