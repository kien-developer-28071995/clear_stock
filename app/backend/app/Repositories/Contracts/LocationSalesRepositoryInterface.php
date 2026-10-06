<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;

/** Per-location daily sales (Growth, multi-location). Keys are local variant/location ids. */
interface LocationSalesRepositoryInterface
{
    /**
     * Replace every row from $fromDate on with $rows (the window is re-aggregated each sync).
     *
     * @param  array<int, array{variant_id: int, location_id: int, date: string, units_sold: int}>  $rows
     */
    public function replaceFrom(Shop $shop, string $fromDate, array $rows): void;

    /**
     * @param  array<int, int>  $variantIds
     * @return array<int, array<int, array<string, array{sold: int, in_stock: bool}>>> variant => location => date => values
     */
    public function rowsBetween(Shop $shop, array $variantIds, string $from, string $to): array;

    /** @return array<int, array<int, true>> variant => location => true, for pairs with sales since $fromDate */
    public function pairsWithSalesSince(Shop $shop, string $fromDate): array;

    /** @param array<int, array{variant_id: int, location_id: int, date: string, units_sold: int, was_in_stock: bool}> $rows */
    public function upsertStockFlags(Shop $shop, array $rows): void;

    public function deleteForShop(Shop $shop): void;
}
