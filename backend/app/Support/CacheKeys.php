<?php

namespace App\Support;

/**
 * Single place for every cache key + TTL. Never build cache keys inline elsewhere.
 */
final class CacheKeys
{
    public const TTL_SHOP = 3600;

    /** Shopify retries failed deliveries for up to 48h; remember ids a bit longer. */
    public const TTL_WEBHOOK_DEDUPE = 60 * 60 * 72;

    public static function shopByDomain(string $domain): string
    {
        return 'shop:domain:'.strtolower($domain);
    }

    public static function shopById(int $id): string
    {
        return 'shop:id:'.$id;
    }

    /** Lock name used while refreshing a shop's expiring offline token. */
    public static function shopTokenRefreshLock(string $domain): string
    {
        return 'lock:shop-token:'.strtolower($domain);
    }

    /** Latest sync run of a shop (polled by the progress bar every few seconds). */
    public static function latestSyncRun(int $shopId): string
    {
        return 'sync-run:latest:'.$shopId;
    }

    public const TTL_LATEST_SYNC_RUN = 300;

    public static function syncRunLock(int $runId): string
    {
        return 'lock:sync-run:'.$runId;
    }

    public static function webhookDelivery(string $webhookId): string
    {
        return 'webhook:delivery:'.$webhookId;
    }
}
