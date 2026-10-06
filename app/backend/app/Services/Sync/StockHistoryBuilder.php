<?php

namespace App\Services\Sync;

use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\LocationSalesRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * Decides, for each day in the window, whether a variant was in stock, so that
 * forecasts can ignore days on which nothing could be sold.
 *
 * Shopify has no inventory history API, so stock is walked backwards from
 * today's stock:  start_of_day(D) = end_of_day(D) + sold(D) - returned(D).
 * Restocks are unknown, which makes the estimate an upper bound: an estimate
 * <= 0 means the variant really had no stock. A day counts as in stock when it
 * started with stock or had sales. Real end-of-day snapshots (recorded by each
 * sync for "yesterday") replace the estimate whenever they exist.
 *
 * Only tracked, active variants with at least one sale in the last year are
 * processed; others get no rows (nothing to forecast from).
 */
class StockHistoryBuilder
{
    private const CHUNK = 200;

    public function __construct(
        private readonly CatalogRepositoryInterface $catalog,
        private readonly DailySalesRepositoryInterface $sales,
        private readonly LocationSalesRepositoryInterface $locationSales,
    ) {}

    /**
     * Same walk per (variant, location) from that location's stock (Growth). Transfers
     * between locations are unknown, like restocks; there are no stored snapshots per location.
     *
     * @return array{pairs: int, out_of_stock_days: int}
     */
    public function rebuildLocations(Shop $shop, string $windowStart, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone)->startOfDay();
        $info = $this->catalog->activeVariantInfo($shop);
        $stock = $this->catalog->stockByVariantAndLocation($shop);
        $pairs = array_filter(
            $this->locationSales->pairsWithSalesSince($shop, $today->subDays(365)->toDateString()),
            fn ($id) => $info[$id]['tracked'] ?? false,
            ARRAY_FILTER_USE_KEY,
        );

        $count = 0;
        $outOfStockDays = 0;
        foreach (array_chunk(array_keys($pairs), self::CHUNK) as $chunk) {
            $rows = $this->locationSales->rowsBetween($shop, $chunk, $windowStart, $today->toDateString());
            $updates = [];

            foreach ($chunk as $variantId) {
                $firstDay = $this->firstDay($shop, $info[$variantId]['created'] ?? null, $windowStart);
                foreach (array_keys($pairs[$variantId]) as $locationId) {
                    $count++;
                    $end = $stock[$variantId][$locationId] ?? 0;
                    for ($day = $today; $day->toDateString() >= $firstDay; $day = $day->subDay()) {
                        $date = $day->toDateString();
                        $sold = $rows[$variantId][$locationId][$date]['sold'] ?? 0;
                        $start = $end + $sold;
                        $inStock = $start > 0 || $sold > 0;
                        if (isset($rows[$variantId][$locationId][$date]) || ! $inStock) {
                            $updates[] = ['variant_id' => $variantId, 'location_id' => $locationId, 'date' => $date, 'units_sold' => $sold, 'was_in_stock' => $inStock];
                        }
                        $outOfStockDays += $inStock ? 0 : 1;
                        $end = $start;
                    }
                }
            }

            $this->locationSales->upsertStockFlags($shop, $updates);
        }

        return ['pairs' => $count, 'out_of_stock_days' => $outOfStockDays];
    }

    private function firstDay(Shop $shop, ?string $created, string $windowStart): string
    {
        return $created !== null
            ? max($windowStart, CarbonImmutable::parse($created, 'UTC')->setTimezone($shop->timezone)->toDateString())
            : $windowStart;
    }

    /** @return array{variants: int, out_of_stock_days: int} */
    public function rebuild(Shop $shop, string $windowStart, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone)->startOfDay();
        $yesterday = $today->subDay()->toDateString();
        $yearAgo = $today->subDays(365)->toDateString();

        $info = $this->catalog->activeVariantInfo($shop);
        $stock = $this->catalog->stockByVariant($shop);
        $variantIds = array_values(array_filter(
            $this->sales->variantIdsWithSalesSince($shop, $yearAgo),
            fn ($id) => ($info[$id]['tracked'] ?? false),
        ));

        $outOfStockDays = 0;

        foreach (array_chunk($variantIds, self::CHUNK) as $chunk) {
            $rows = $this->sales->rowsBetween($shop, $chunk, $windowStart, $today->toDateString());
            $updates = [];

            foreach ($chunk as $variantId) {
                $firstDay = $windowStart;
                if (($created = $info[$variantId]['created'] ?? null) !== null) {
                    $firstDay = max($firstDay, CarbonImmutable::parse($created, 'UTC')->setTimezone($shop->timezone)->toDateString());
                }

                $end = $stock[$variantId] ?? 0; // end of "today" = now
                for ($day = $today; $day->toDateString() >= $firstDay; $day = $day->subDay()) {
                    $date = $day->toDateString();
                    $row = $rows[$variantId][$date] ?? null;
                    $sold = $row['sold'] ?? 0;
                    $returned = $row['returned'] ?? 0;

                    if ($date !== $today->toDateString() && ($row['stock'] ?? null) !== null) {
                        $end = $row['stock']; // real snapshot beats the estimate
                    }

                    $start = $end + $sold - $returned;
                    $inStock = $start > 0 || $sold > 0;
                    $snapshot = $date === $yesterday ? $end : ($row['stock'] ?? null);

                    if ($row !== null || ! $inStock || $date === $yesterday) {
                        $updates[] = [
                            'variant_id' => $variantId,
                            'date' => $date,
                            'units_sold' => $sold,
                            'units_returned' => $returned,
                            'end_of_day_stock' => $snapshot,
                            'was_in_stock' => $inStock,
                        ];
                    }
                    $outOfStockDays += $inStock ? 0 : 1;
                    $end = $start;
                }
            }

            $this->sales->upsertStockFlags($shop, $updates);
        }

        return ['variants' => count($variantIds), 'out_of_stock_days' => $outOfStockDays];
    }
}
