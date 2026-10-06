<?php

namespace App\Support;

/** Shopify global ids: gid://shopify/ProductVariant/123 <-> 123 */
final class Gid
{
    public static function id(?string $gid): ?int
    {
        if ($gid === null || ! preg_match('#/(\d+)$#', $gid, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    public static function type(string $gid): ?string
    {
        return preg_match('#^gid://shopify/([A-Za-z]+)/#', $gid, $m) ? $m[1] : null;
    }
}
