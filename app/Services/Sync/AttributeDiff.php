<?php

namespace App\Services\Sync;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Compares an existing model's attributes against a freshly mapped
 * attribute array (from a data-source-specific row mapper) and reports
 * only the keys that actually changed. Pure logic, no DB access, no
 * dependency on any particular data source.
 *
 * Values are normalized before comparison rather than using a bare `===`:
 * decimal-cast columns are always returned by Laravel as a *string* (e.g.
 * "35.658581"), while a row mapper typically produces a PHP float for the
 * same column -- an unnormalized `===` would flag every row with such a
 * column as "changed" on every single reimport. Likewise, a `date`-cast
 * column comes back as a Carbon instance (never `===`-equal to a mapper's
 * plain date string) and an `AsEnumCollection`-cast column comes back as
 * an Illuminate\Support\Collection (never `===`-equal to, nor even
 * `is_array()`-true against, a mapper's plain array) -- both are unwrapped
 * to a plain comparable value first. JSON-valued columns are also
 * key-sorted before comparing so a mapper that builds an array in a
 * different (but content-equal) key order can't silently trigger the same
 * kind of false-positive Updated event flood.
 */
final class AttributeDiff
{
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $mapped
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $current, array $mapped): array
    {
        $changes = [];

        foreach ($mapped as $key => $newValue) {
            $oldValue = $current[$key] ?? null;

            if (! self::areEqual($oldValue, $newValue)) {
                $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }

    private static function areEqual(mixed $old, mixed $new): bool
    {
        $old = self::unwrap($old);
        $new = self::unwrap($new);

        if ($old === null || $new === null) {
            return $old === $new;
        }

        if (is_array($old) && is_array($new)) {
            return self::normalizeArray($old) === self::normalizeArray($new);
        }

        if (is_scalar($old) && is_scalar($new)) {
            return self::normalizeScalar($old) === self::normalizeScalar($new);
        }

        return $old === $new;
    }

    /**
     * Reduces Eloquent-cast objects to the plain value a mapper would
     * naturally produce for the same column, so the rest of the
     * comparison never has to special-case them.
     */
    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return $value;
    }

    /**
     * Only a genuine PHP float (e.g. a row mapper's parsed latitude/
     * longitude) gets reformatted -- to the same fixed 6-decimal-place
     * shape Laravel's decimal:6 cast always produces on the DB side
     * (verified: 35.5 round-trips as the string "35.500000", never
     * trimmed). Numeric-looking strings such as prefecture_code ("01")
     * are deliberately left untouched: reformatting them as decimals would
     * silently treat "01" and "1" as equal, which is wrong for a code.
     */
    private static function normalizeScalar(int|float|string|bool $value): string
    {
        if (is_float($value)) {
            return number_format($value, 6, '.', '');
        }

        return (string) $value;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function normalizeArray(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalizeArray($item);
            }
        }

        return $value;
    }
}
