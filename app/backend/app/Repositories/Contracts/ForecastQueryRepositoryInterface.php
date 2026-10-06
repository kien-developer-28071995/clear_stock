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

    /**
     * What is due to reorder now, summed per supplier at the supplier's price (without the
     * landed cost share).
     *
     * @return array<int, array{products: int, units: int, cost: float}>
     */
    public function dueBySupplier(Shop $shop, string $today): array;

    /** @return array{total: int, tracked: int, reorder_now: int, out_of_stock: int, slow: int, healthy: int} total = forecasted, tracked = forecastable products */
    public function counts(Shop $shop, string $today): array;

    /** Selling products with at most $days of stock left whose reorder date is still ahead (not discontinued). */
    public function lowCover(Shop $shop, string $today, int $days): Collection;

    /**
     * Products needing action by $until (out of stock, or reorder date reached), most urgent first.
     *
     * @return Collection<int, Forecast>
     */
    public function actionItems(Shop $shop, string $until, int $limit): Collection;

    /**
     * Products put off until a later day ("not now"), soonest back first.
     *
     * @return array<int, array{variant_id: int, name: string, until: string}>
     */
    public function snoozed(Shop $shop, string $today, int $limit): array;

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
     * Sales missed on the out-of-stock days of the last 30 days (combined forecasts): units, and
     * revenue at the current selling price. Top products by lost revenue.
     *
     * @return array{units: float, revenue: float, count: int, missing_price: int, top: array<int, array{variant_id: int, name: string, sku: ?string, stock: int, out_of_stock_days: int, units: float, revenue: ?float}>}
     */
    public function lostSales(Shop $shop, int $limit): array;

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

    /** @return array{count: int, units: int, value: float, missing_cost: int} discontinued products that still have stock */
    public function discontinuedStock(Shop $shop): array;

    /** @return array<int, string> weeks (Y-m-d, newest first) with forecast snapshots, up to $latestStart */
    /**
     * Slow and overstocked products, most stock value first, with what sold since $since and the last sale.
     *
     * @return array<int, array{variant_id: int, name: string, sku: ?string, status: string, stock: int, excess: int, days_of_cover: ?float, value: ?float, sold: int, sell_through: ?float, last_sold_on: ?string}>
     */
    public function clearance(Shop $shop, string $today, string $since, int $limit): array;

    /**
     * Forecast products grouped by Shopify product (not discontinued), for products with several variants.
     *
     * @return array<int, array<int, array{variant_id: int, product: string, title: ?string, avg: float, stock: int, days_of_cover: ?float, reorder_date: ?string}>>
     */
    public function variantsByProduct(Shop $shop): array;

    /** The latest weekly snapshot of a product from before $weekStart: {week_start, avg} or null. */
    public function previousSnapshot(Shop $shop, int $variantId, string $weekStart): ?array;

    public function snapshotWeeks(Shop $shop, string $latestStart, int $limit): array;

    /**
     * The forecast of each product in a snapshot week next to what it really sold over the following
     * $horizonDays: net units on in-stock days and the number of out-of-stock days. Active products
     * only; forecasts that included bundle sales are left out (bundle demand is not in own sales).
     *
     * @param  ?int  $variantId  one product only
     * @return array<int, array{variant_id: int, name: string, sku: ?string, predicted: float, source: string, units: int, oos_days: int}>
     */
    public function accuracyRows(Shop $shop, string $weekStart, int $horizonDays, ?int $variantId = null): array;
}
