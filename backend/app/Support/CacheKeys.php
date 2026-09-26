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

    /**
     * Bumped on every catalog write: products, locations, inventory, bundles, product
     * settings, suppliers. Catalog read caches include it in their key.
     */
    public static function catalogVersion(int $shopId): string
    {
        return 'catalog:version:'.$shopId;
    }

    public const TTL_CATALOG = 3600;

    /** A catalog read ($part: locations, bundles, tracked-count…) at a catalog version. */
    public static function catalog(int $shopId, int $version, string $part): string
    {
        return "catalog:{$shopId}:v{$version}:{$part}";
    }

    /** A page of the product list, at a forecast and a catalog version. */
    public static function forecastPage(int $shopId, int $forecastVersion, int $catalogVersion, string $today, string $filtersHash): string
    {
        return "forecasts:page:{$shopId}:f{$forecastVersion}:c{$catalogVersion}:{$today}:{$filtersHash}";
    }

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
    /** Short lock against sending the same supplier two emails by double-clicking. */
    public static function supplierEmailLock(int $supplierId): string
    {
        return 'supplier-email:lock:'.$supplierId;
    }

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
