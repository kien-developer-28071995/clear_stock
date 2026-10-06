<?php

namespace App\Support;

use App\Models\Shop;
use LogicException;

/** Request-scoped holder for the authenticated shop. */
final class ShopContext
{
    private ?Shop $shop = null;

    public function set(Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function shop(): Shop
    {
        return $this->shop ?? throw new LogicException('No shop in context: route is missing the shopify.session middleware.');
    }

    public function has(): bool
    {
        return $this->shop !== null;
    }
}
