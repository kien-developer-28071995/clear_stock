<?php

namespace App\Repositories\Contracts;

use App\Models\Forecast;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Models\VariantAlertState;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Live stock and per-variant alert state for real-time alerts. */
interface RealtimeAlertRepositoryInterface
{
    public function variantByInventoryItem(Shop $shop, int $inventoryItemId): ?Variant;

    public function locationByShopifyId(Shop $shop, int $shopifyLocationId): ?Location;

    /** Store the new available quantity unless a newer update was already applied. Returns false when stale. */
    public function applyInventoryLevel(Shop $shop, Variant $variant, Location $location, int $available, CarbonInterface $updatedAt): bool;

    /** @return array{available: int, incoming: int} summed over active locations */
    public function stockTotals(Variant $variant): array;

    /** @return array<int, array<int, array{name: string, available: int}>> variant id => active locations */
    public function stockByLocation(Shop $shop, array $variantIds): array;

    /** The whole-variant forecast (all locations), or null when none was computed. */
    public function totalForecast(Variant $variant): ?Forecast;

    /** @return Collection<int, Forecast> whole-variant forecasts keyed by variant id, variant loaded */
    public function totalForecasts(Shop $shop, array $variantIds): Collection;

    /**
     * Record the current level of every forecast product without flagging anything as pending,
     * so turning real-time alerts on doesn't report products that were already low.
     *
     * @param  array<int, string>  $levels  variant id => StockLevel value
     */
    public function seedStates(Shop $shop, array $levels): void;

    public function state(Variant $variant): VariantAlertState;

    public function saveState(VariantAlertState $state): void;

    /** @return Collection<int, VariantAlertState> with variant loaded */
    public function pendingStates(Shop $shop): Collection;

    /** @param array<int, int> $stateIds */
    public function clearPending(array $stateIds, CarbonInterface $upTo): void;

    /** @return array<int, int> ids of shops with alerts waiting to be sent */
    public function shopsWithPending(): array;

    /** @return array<int, int> ids of installed shops that have real-time alerts on or still hold a subscription */
    public function shopsToReconcile(): array;
}
