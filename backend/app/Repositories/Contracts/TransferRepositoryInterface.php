<?php

namespace App\Repositories\Contracts;

use App\Models\InventoryTransfer;
use App\Models\Shop;
use Illuminate\Support\Collection;

interface TransferRepositoryInterface
{
    /**
     * Per-location forecasts of every tracked, active product at active locations, grouped
     * by variant, with what the planner and the page need.
     *
     * @return Collection<int, Collection<int, object>> keyed by variant_id
     */
    public function locationForecasts(Shop $shop): Collection;

    /** @return Collection<int, InventoryTransfer> newest first, with their locations */
    public function createdSince(Shop $shop, \DateTimeInterface $since): Collection;

    public function create(Shop $shop, array $attributes): InventoryTransfer;
}
