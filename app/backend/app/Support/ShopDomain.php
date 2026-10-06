<?php

namespace App\Support;

final class ShopDomain
{
    private const PATTERN = '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/';

    /** Returns the normalized domain, or null if it is not a valid *.myshopify.com host. */
    public static function normalize(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string) $domain, '/');

        return preg_match(self::PATTERN, $domain) ? $domain : null;
    }
}
