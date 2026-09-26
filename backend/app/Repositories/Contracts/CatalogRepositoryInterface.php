<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use Illuminate\Support\Carbon;

/** Write-heavy catalog storage used by the sync (locations, variants, bundles, inventory). */
interface CatalogRepositoryInterface
{
    /** @param array<int, array{shopify_location_id: int, name: string, is_active: bool}> $rows */
    public function upsertLocations(Shop $shop, array $rows): void;

    /** @return array<int, int> shopify_location_id => locations.id (active only) */
    public function activeLocationIds(Shop $shop): array;

    /** @param array<int, array<string, mixed>> $rows keyed by column name, one per variant */
    public function upsertVariants(Shop $shop, array $rows): void;

    /** @return array<int, int> shopify_variant_id => variants.id */
    public function variantIdMap(Shop $shop): array;

    /**
     * Replace the Shopify-sourced components of the given bundles.
     *
     * @param  array<int, int>  $bundleVariantIds
     * @param  array<int, array{bundle_variant_id: int, component_variant_id: int, quantity: int}>  $components
     */
    public function replaceShopifyBundleComponents(Shop $shop, array $bundleVariantIds, array $components): void;

    /** @param array<int, array{variant_id: int, location_id: int, available: int}> $rows */
    public function upsertInventoryLevels(Shop $shop, array $rows): void;

    public function deleteInventoryLevelsNotSeenSince(Shop $shop, Carbon $since): int;

    /** @param array<int, int> $variantIds variants still present in Shopify */
    public function deactivateVariantsNotIn(Shop $shop, array $variantIds): int;

    /** @param array<int, array{variant_id: int, tracked: bool}> $rows */
    public function updateTracked(Shop $shop, array $rows): void;

    /** @return array<int, int> variants.id => total available across active locations */
    public function stockByVariant(Shop $shop): array;

    /** @return array<int, array<int, int>> variants.id => locations.id => available (active locations) */
    public function stockByVariantAndLocation(Shop $shop): array;

    /** @return array<int, array{tracked: bool, created: ?string}> variants.id => info (active variants) */
    public function activeVariantInfo(Shop $shop): array;
}
