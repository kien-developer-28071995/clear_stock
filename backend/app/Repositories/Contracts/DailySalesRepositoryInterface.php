<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;

interface DailySalesRepositoryInterface
{
    /** Zero units sold/returned from $fromDate on (stock snapshots are kept); the window is then re-filled. */
    public function resetSalesFrom(Shop $shop, string $fromDate): void;

    /** @param array<int, array{variant_id: int, date: string, units_sold: int, units_returned: int}> $rows */
    public function upsertSales(Shop $shop, array $rows): void;

    /** @return array<int, int> variant ids with at least one unit sold since $fromDate */
    public function variantIdsWithSalesSince(Shop $shop, string $fromDate): array;

    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, array<string, array{sold: int, returned: int, stock: ?int}>> variant_id => date => values
     */
    public function rowsBetween(Shop $shop, array $variantIds, string $from, string $to): array;

    /** @param array<int, array{variant_id: int, date: string, units_sold: int, units_returned: int, end_of_day_stock: ?int, was_in_stock: bool}> $rows */
    public function upsertStockFlags(Shop $shop, array $rows): void;
}
