<?php

namespace App\Support;

/** Languages the embedded app is translated into (config app.supported_locales). */
final class Locales
{
    /** @return array<int, string> */
    public static function supported(): array
    {
        return config('app.supported_locales');
    }
}
