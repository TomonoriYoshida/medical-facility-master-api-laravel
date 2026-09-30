<?php

namespace App\Logging;

use Monolog\Handler\DeduplicationHandler;
use Monolog\LogRecord;
use Throwable;

/**
 * Passes each record on as soon as it is logged, unless a record with the
 * same level and key was already passed on within the window.
 *
 * Monolog's DeduplicationHandler buffers records until the handler closes,
 * which for queue:work is only when the worker exits (up to an hour later).
 * The key is the exception's class and where in the project it came from
 * rather than the message, because messages such as a QueryException's embed
 * each request's bindings and would never repeat verbatim while the database
 * is down.
 */
class ImmediateDeduplicationHandler extends DeduplicationHandler
{
    public function isHandling(LogRecord $record): bool
    {
        return $this->deduplicationLevel->includes($record->level);
    }

    public function handle(LogRecord $record): bool
    {
        $handled = parent::handle($record);

        $this->flush();

        return $handled;
    }

    /**
     * @param  array<string>  $store
     */
    protected function isDuplicate(array $store, LogRecord $record): bool
    {
        $since = $record->datetime->getTimestamp() - $this->time;
        $yesterday = time() - 86400;
        $key = $this->deduplicationKey($record);

        for ($i = count($store) - 1; $i >= 0; $i--) {
            $parts = explode(':', $store[$i], 3);

            // A line cut short by a concurrent write from another container.
            if (count($parts) < 3) {
                continue;
            }

            [$timestamp, $level, $storedKey] = $parts;

            if ($level === $record->level->getName() && $storedKey === $key && (int) $timestamp > $since) {
                return true;
            }

            if ((int) $timestamp < $yesterday) {
                $this->gc = true;
            }
        }

        return false;
    }

    protected function buildDeduplicationStoreEntry(LogRecord $record): string
    {
        return $record->datetime->getTimestamp().':'.$record->level->getName().':'.$this->deduplicationKey($record);
    }

    private function deduplicationKey(LogRecord $record): string
    {
        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $origin = ExceptionOrigin::of($exception);

            return $exception::class.'@'.$origin['file'].':'.$origin['line'];
        }

        return (string) preg_replace('{[\r\n].*}s', '', $record->message);
    }
}
