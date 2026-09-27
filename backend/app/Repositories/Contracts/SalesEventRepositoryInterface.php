<?php

namespace App\Repositories\Contracts;

use App\Models\SalesEvent;
use App\Models\Shop;
use Illuminate\Support\Collection;

interface SalesEventRepositoryInterface
{
    /** @return Collection<int, SalesEvent> newest start first, with the supplier */
    public function allForShop(Shop $shop): Collection;

    /** @return Collection<int, SalesEvent> events ending on or after $fromDate (the ones a forecast can use) */
    public function endingFrom(Shop $shop, string $fromDate): Collection;

    public function find(Shop $shop, int $id): ?SalesEvent;

    public function create(Shop $shop, array $attributes): SalesEvent;

    public function update(SalesEvent $event, array $attributes): SalesEvent;

    public function delete(SalesEvent $event): void;
}
