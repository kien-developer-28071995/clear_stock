<?php

namespace App\Observers;

use App\Models\Shop;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class ShopObserver
{
    public function saved(Shop $shop): void
    {
        $this->forget($shop);
    }

    public function deleted(Shop $shop): void
    {
        $this->forget($shop);
    }

    private function forget(Shop $shop): void
    {
        Cache::forget(CacheKeys::shopById($shop->id));
        Cache::forget(CacheKeys::shopByDomain($shop->domain));
    }
}
