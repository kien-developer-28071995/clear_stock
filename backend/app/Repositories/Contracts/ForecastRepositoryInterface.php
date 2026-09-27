<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Support\Collection;

interface ForecastRepositoryInterface
{
    /** @return array<int, int> ids of active, inventory-tracked variants (the ones we forecast) */
    public function forecastableVariantIds(Shop $shop): array;

    /** @return array<int, int> ids of variants the merchant no longer reorders */
    public function discontinuedVariantIds(Shop $shop): array;

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Variant> with supplier loaded, keyed by id
     */
    public function variantsWithSuppliers(Shop $shop, array $ids): Collection;

    /**
     * Merchant-defined (manual) bundles that contain the given components.
     *
     * @param  array<int, int>  $componentIds
     * @return array<int, array<int, array{quantity: int, name: string}>> component id => bundle id => info
     */
    public function manualBundlesContaining(Shop $shop, array $componentIds): array;

    /**
     * Active (not expired) overrides.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, array<string, array{value: float, note: ?string, expires_at: ?string}>> variant id => field => override
     */
    public function activeOverrides(Shop $shop, array $variantIds): array;

    /** @param array<int, array<string, mixed>> $rows combined (all-locations) forecasts to write */
    public function replaceForecasts(Shop $shop, array $rows): void;

    /** Create or replace one override (merchant adjustment of a forecast input). */
    public function setOverride(Shop $shop, int $variantId, string $field, float $value, ?string $note, ?string $expiresAt): void;

    public function removeOverride(Shop $shop, int $variantId, string $field): void;

    /**
     * Replace the per-location forecasts of these variants.
     *
     * @param  array<int, int>  $variantIds
     * @param  array<int, array<string, mixed>>  $rows  each with location_id
     */
    public function replaceLocationForecasts(Shop $shop, array $variantIds, array $rows): void;

    public function deleteLocationForecasts(Shop $shop): int;

    /** @param array<int, int> $keepVariantIds */
    public function deleteForecastsExcept(Shop $shop, array $keepVariantIds): int;

    /**
     * Reference products (new products borrow their rate): name and current combined
     * average, null when the reference has no forecast.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, array{name: string, avg: ?float}>
     */
    public function referenceRates(Shop $shop, array $variantIds): array;

    /**
     * Revenue per variant since a date: (units sold - returned) x current price.
     * Variants without a price are left out (they cannot be classified).
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, float>
     */
    public function revenueSince(Shop $shop, array $variantIds, string $fromDate): array;

    /**
     * Store the ABC classes; every other variant of the shop is reset to unclassified.
     *
     * @param  array<int, array{class: string, revenue: float, share: float}>  $classes  variant id => class
     */
    public function saveAbcClasses(Shop $shop, array $classes): void;

    /**
     * Active manual bundles with any of these components, with every component and its price.
     *
     * @param  array<int, int>  $componentIds
     * @return array{bundles: array<int, array<int, int>>, prices: array<int, ?float>} bundle id => component id => units; component id => price
     */
    public function bundlesWithComponents(Shop $shop, array $componentIds): array;

    /**
     * The week's forecast rates, kept to measure accuracy later. The first full forecast run
     * of a week writes them; later runs that week leave them as they are.
     *
     * @param  array<int, array{variant_id: int, avg_daily_sales: float, avg_source: string, has_bundles: bool}>  $rows
     */
    public function saveWeeklySnapshots(Shop $shop, string $weekStart, array $rows): void;

    /** Snapshots older than this date are deleted. */
    public function pruneSnapshots(Shop $shop, string $before): int;
}
