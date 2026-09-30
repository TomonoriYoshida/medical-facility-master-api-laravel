<?php

namespace App\Logging;

use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Shortens a record so it fits in one chat message: Discord rejects the whole
 * message when a field exceeds 1,024 characters, and a full stack trace or a
 * failed SQL statement easily does. The complete record stays in the log file.
 */
class SummarizeForAlert implements ProcessorInterface
{
    private const int MESSAGE_LIMIT = 1500;

    private const int FIELD_LIMIT = 500;

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        if (! app()->runningInConsole()) {
            $extra['request'] = request()->method().' '.Str::limit(request()->getRequestUri(), self::FIELD_LIMIT);
        }

        return $record->with(
            message: Str::limit($record->message, self::MESSAGE_LIMIT),
            context: array_map($this->summarize(...), $record->context),
            extra: $extra,
        );
    }

    private function summarize(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof Throwable) {
            $origin = ExceptionOrigin::of($value);
            $file = Str::after($origin['file'], base_path().DIRECTORY_SEPARATOR);

            return sprintf('%s (%s:%d)', $value::class, $file, $origin['line']);
        }

        if (is_string($value)) {
            return Str::limit($value, self::FIELD_LIMIT);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::FIELD_LIMIT);
    }
}
