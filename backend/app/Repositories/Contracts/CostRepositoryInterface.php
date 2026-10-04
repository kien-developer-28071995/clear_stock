<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use Illuminate\Support\Collection;

interface CostRepositoryInterface
{
    /** @return array{tracked: int, missing: int, overridden: int} active, inventory-tracked products */
    public function counts(Shop $shop): array;

    /** @return Collection<int, object{id: int, product_title: string, title: ?string, sku: ?string, barcode: ?string, shopify_unit_cost: ?string, cost_override: ?string, unit_cost: ?string}> */
    public function list(Shop $shop, bool $missingOnly, ?string $search, int $limit): Collection;

    /**
     * Sets (or clears, null) the app cost of these products and recomputes unit_cost.
     *
     * @param  array<int, ?float>  $costs  variant id => cost
     * @return int products updated (other shops' ids are ignored)
     */
    public function setOverrides(Shop $shop, array $costs): int;

    /** @return array<string, int> SKU and barcode => variant id (active products; first match wins) */
    public function idsBySkuAndBarcode(Shop $shop): array;

    /**
     * Brings variants.unit_cost in line with the suppliers' landed cost share: base cost x (1 + %)
     * for products of a supplier that has one, the plain base cost again for products that left it.
     */
    public function applyLandedCosts(Shop $shop): void;
}
