<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;

interface InventorySnapshotRepositoryInterface
{
    /**
     * Stores (or replaces) the day's totals from the stock of tracked, active products.
     *
     * @param  array<int, int>  $stock  variant id => units on hand (all active locations)
     */
    public function record(Shop $shop, string $date, array $stock): void;

    /** @return array<int, array{date: string, units: int, value: float, products_in_stock: int, products_missing_cost: int}> oldest first */
    public function since(Shop $shop, string $from): array;

    public function prune(Shop $shop, string $before): int;
}
