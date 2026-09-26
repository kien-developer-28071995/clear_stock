<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Version counters for "invalidate everything of this kind for a shop": caches put the
 * version in their key, writers bump it. Keys come from CacheKeys.
 */
final class CacheVersion
{
    public static function current(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    public static function bump(string $key): void
    {
        Cache::add($key, 0, now()->addDays(30));
        Cache::increment($key);
    }

    public static function catalog(int $shopId): int
    {
        return self::current(CacheKeys::catalogVersion($shopId));
    }

    public static function bumpCatalog(int $shopId): void
    {
        self::bump(CacheKeys::catalogVersion($shopId));
    }
}
