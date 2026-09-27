<?php

namespace App\Repositories\Contracts;

use App\Models\ManualOrder;
use App\Models\Shop;
use Illuminate\Support\Collection;

interface ManualOrderRepositoryInterface
{
    /**
     * Open orders still counted as on the way (expected on or after $countedFrom), per variant.
     *
     * @param  array<int, int>|null  $variantIds  null = every variant
     * @return array<int, array{units: int, expected_on: string, orders: int}> variant id => total, earliest date
     */
    public function onTheWay(Shop $shop, string $countedFrom, ?array $variantIds = null): array;

    /** @return Collection<int, ManualOrder> with variant and supplier; open first, then the latest closed */
    public function list(Shop $shop, bool $open, int $limit): Collection;

    public function find(Shop $shop, int $id): ?ManualOrder;

    /** @param array<int, array<string, mixed>> $rows */
    public function createMany(Shop $shop, array $rows): void;

    public function update(ManualOrder $order, array $attributes): ManualOrder;

    /** @return array<int, int> variant id => lead time days of its current forecast */
    public function leadTimes(Shop $shop, array $variantIds): array;

    /** @return array{orders: int, cost: float, missing_cost: int} not cancelled, ordered on or after $fromDate, at current unit cost */
    public function spentSince(Shop $shop, string $fromDate): array;
}
