<?php

namespace App\Support;

/** Shopify global ids: gid://shopify/ProductVariant/123 <-> 123 */
final class Gid
{
    /** Anything that is not a gid (a missing field, another type) is null: exports are read as they come. */
    public static function id(mixed $gid): ?int
    {
        if (! is_string($gid) || ! preg_match('#/(\d{1,18})$#', $gid, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    public static function type(mixed $gid): ?string
    {
        return is_string($gid) && preg_match('#^gid://shopify/([A-Za-z]+)/#', $gid, $m) ? $m[1] : null;
    }
}
