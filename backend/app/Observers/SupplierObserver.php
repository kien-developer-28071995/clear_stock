<?php

namespace App\Observers;

use App\Models\Supplier;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

class SupplierObserver
{
    public function saved(Supplier $supplier): void
    {
        Cache::forget(CacheKeys::suppliers($supplier->shop_id));
    }

    public function deleted(Supplier $supplier): void
    {
        Cache::forget(CacheKeys::suppliers($supplier->shop_id));
    }
}
