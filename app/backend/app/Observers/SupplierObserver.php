<?php

namespace App\Observers;

use App\Models\Supplier;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Cache;

class SupplierObserver
{
    public function saved(Supplier $supplier): void
    {
        $this->forget($supplier);
    }

    public function deleted(Supplier $supplier): void
    {
        $this->forget($supplier);
    }

    private function forget(Supplier $supplier): void
    {
        Cache::forget(CacheKeys::suppliers($supplier->shop_id));
        CacheVersion::bumpCatalog($supplier->shop_id); // supplier names show in the product list
    }
}
