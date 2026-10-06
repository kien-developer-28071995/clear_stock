<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Support\Collection;

interface SupplierRepositoryInterface
{
    /** @return Collection<int, Supplier> with variants_count, ordered by name */
    public function allForShop(Shop $shop): Collection;

    public function find(Shop $shop, int $id): ?Supplier;

    public function create(Shop $shop, array $attributes): Supplier;

    public function update(Supplier $supplier, array $attributes): Supplier;

    /** Variants of the supplier fall back to the shop default lead time. */
    public function delete(Supplier $supplier): void;
}
