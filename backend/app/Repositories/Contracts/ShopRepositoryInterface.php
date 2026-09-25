<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;

interface ShopRepositoryInterface
{
    public function findById(int $id): ?Shop;

    public function findByDomain(string $domain): ?Shop;

    /** Create the shop if missing, otherwise update it. */
    public function updateOrCreateByDomain(string $domain, array $attributes): Shop;

    public function update(Shop $shop, array $attributes): Shop;

    /** Permanently delete the shop and every row that belongs to it. */
    public function purge(Shop $shop): void;
}
