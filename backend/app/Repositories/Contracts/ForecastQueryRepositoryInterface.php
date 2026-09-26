<?php

namespace App\Repositories\Contracts;

use App\Models\Forecast;
use App\Models\Shop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Read side of forecasts (SKU list, detail, dashboard). $today is the shop's local date. */
interface ForecastQueryRepositoryInterface
{
    /** @param array{status?: ?string, search?: ?string, sort?: ?string, location_id?: ?int} $filters location_id = per-location forecasts */
    public function paginate(Shop $shop, array $filters, string $today, int $perPage, int $page): LengthAwarePaginator;

    public function findForVariant(Shop $shop, int $variantId): ?Forecast;

    /**
     * Stock and (when available) the per-location forecast of a variant, active locations only.
     *
     * @return array<int, array{location_id: int, location: string, available: int, forecast: ?Forecast}>
     */
    public function byLocation(Shop $shop, int $variantId): array;

    /** @return array<int, array{id: int, name: string}> */
    public function activeLocations(Shop $shop): array;

    /**
     * Products to reorder now (optionally for one supplier), for purchase orders.
     *
     * @return Collection<int, Forecast> with variant.supplier
     */
    /** @param array<int, int>|null $variantIds only these (any reorder date), e.g. rows picked on the home screen */
    public function reorderList(Shop $shop, string $today, ?int $supplierId, ?int $locationId = null, ?array $variantIds = null): Collection;

    /** @return array{total: int, tracked: int, reorder_now: int, out_of_stock: int, slow: int, healthy: int} total = forecasted, tracked = forecastable products */
    public function counts(Shop $shop, string $today): array;

    /**
     * Products needing action by $until (out of stock, or reorder date reached), most urgent first.
     *
     * @return Collection<int, Forecast>
     */
    public function actionItems(Shop $shop, string $until, int $limit): Collection;

    /**
     * Selling products with the fewest days of stock left (for the runway chart).
     *
     * @return Collection<int, Forecast>
     */
    public function runway(Shop $shop, int $limit): Collection;

    /** @return array{value: float, count: int, missing_cost: int, top: array<int, array{variant_id: int, name: string, sku: ?string, stock: int, value: float, days_of_cover: ?float}>} */
    public function slowMovers(Shop $shop, int $limit): array;

    /**
     * Overstocked products: units above the order-up-to level and their cost value.
     *
     * @return array{value: float, units: int, count: int, missing_cost: int, top: array<int, array{variant_id: int, name: string, sku: ?string, stock: int, target: int, excess: int, value: float}>}
     */
    public function overstock(Shop $shop, string $today, int $limit): array;

    /**
     * Per ABC class (combined forecasts of active products): products, revenue and stock value.
     *
     * @return array{classes: array<string, array{count: int, revenue: float, revenue_share: float, stock_value: float}>, unclassified: int, missing_cost: int}
     */
    public function abcSummary(Shop $shop): array;

    /**
     * Combined forecasts with what the reorder maths needs (growth what-if) and the stored
     * forecast (Flow triggers), read in chunks.
     * Filters: supplier_id, vendor, abc.
     *
     * @return iterable<int, array{variant_id: int, name: string, sku: ?string, supplier: ?string, unit_cost: ?float, as_of: string,
     *     avg: float, stock: int, incoming: int, lead_time_days: int, safety_days: int, min_stock: ?int, max_stock: ?int,
     *     min_order_qty: ?int, pack_size: ?int}>
     */
    public function planningRows(Shop $shop, array $filters): iterable;
}
