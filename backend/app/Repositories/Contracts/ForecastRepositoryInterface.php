<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Support\Collection;

interface ForecastRepositoryInterface
{
    /** @return array<int, int> ids of active, inventory-tracked variants (the ones we forecast) */
    public function forecastableVariantIds(Shop $shop): array;

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
}
