<?php

namespace App\Observers;

use App\Models\SupplierEmail;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/** The supplier list shows when each supplier was last emailed. */
class SupplierEmailObserver
{
    public function created(SupplierEmail $email): void
    {
        Cache::forget(CacheKeys::suppliers($email->shop_id));
    }
}
