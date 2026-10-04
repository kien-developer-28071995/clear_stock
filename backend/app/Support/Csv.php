<?php

namespace App\Support;

/** CSV output helpers. */
final class Csv
{
    /**
     * Text that a spreadsheet would run as a formula (a product or shop name starting with
     * = + - @) gets a leading apostrophe, so opening an export never executes anything.
     * Numbers (also negative ones) are left alone.
     *
     * @param  array<int, mixed>  $row
     * @return array<int, mixed>
     */
    public static function safe(array $row): array
    {
        return array_map(
            fn ($cell) => is_string($cell) && $cell !== '' && ! is_numeric($cell) && strpbrk($cell[0], "=+-@\t\r") !== false ? "'".$cell : $cell,
            $row,
        );
    }
}
