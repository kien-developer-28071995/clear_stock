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

    /**
     * Bumped on every forecast write. Read caches (dashboard) include it in their key,
     * so they invalidate themselves without tracking which rows changed.
     */
    public static function forecastVersion(int $shopId): string
    {
        return 'forecast:version:'.$shopId;
    }

    public const TTL_DASHBOARD = 3600;

    public static function dashboard(int $shopId, int $version, string $today): string
    {
        return "dashboard:{$shopId}:v{$version}:{$today}";
    }

    public const TTL_SUPPLIERS = 3600;

    public static function suppliers(int $shopId): string
    {
        return 'suppliers:'.$shopId;
    }

    public const TTL_ALERT_SETTING = 3600;

    public static function alertSetting(int $shopId): string
    {
        return 'alert-setting:'.$shopId;
    }

    /** Shop contact email from Shopify, used to pre-fill the onboarding form. */
    public static function shopContactEmail(int $shopId): string
    {
        return 'shop:contact-email:'.$shopId;
    }

    /** Throttles re-exchanging a token to pick up newly granted scopes. */
    public static function scopeRecheck(int $shopId): string
    {
        return 'shop:scope-recheck:'.$shopId;
    }

    public static function webhookDelivery(string $webhookId): string
    {
        return 'webhook:delivery:'.$webhookId;
    }
}
