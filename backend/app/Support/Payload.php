<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Reads fields of records that come from outside (Shopify exports and webhooks) as what they
 * should be, or as nothing. Such data is trusted for its origin, not for its shape: a field may be
 * missing, null or of another type, and one odd record must never stop a sync.
 */
final class Payload
{
    /** A nested value: Payload::get($line, 'product', 'id'). Null when any step is not there. */
    public static function get(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /** Trimmed text cut to a column's length; null when empty or not text. */
    public static function text(mixed $value, int $max = 255): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** A whole number within bounds; null when it is not a number. */
    public static function int(mixed $value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        return is_numeric($value) && is_finite((float) $value) ? (int) max($min, min($max, (float) $value)) : null;
    }

    /** An amount of money as the export writes it ("4.50"); null when missing, negative or absurd. */
    public static function money(mixed $value): ?string
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value >= 100_000_000) {
            return null;
        }

        return (string) round((float) $value, 4);
    }

    /** A moment in time in UTC ("Y-m-d H:i:s"); null when it is not one the database can hold. */
    public static function utc(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $time = Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }

        return $time->year >= 1971 && $time->year <= 2037 ? $time->toDateTimeString() : null;
    }
}
