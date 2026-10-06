<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierEmail;
use App\Repositories\Contracts\SupplierEmailRepositoryInterface;
use Illuminate\Support\Carbon;

class EloquentSupplierEmailRepository implements SupplierEmailRepositoryInterface
{
    public function log(Shop $shop, Supplier $supplier, string $trigger, string $to, ?string $replyTo, array $items): SupplierEmail
    {
        return SupplierEmail::query()->create([
            'shop_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'trigger' => $trigger,
            'to_email' => $to,
            'reply_to' => $replyTo,
            'items' => $items,
            'total_units' => array_sum(array_column($items, 'quantity')),
        ]);
    }

    public function lastSentAt(Supplier $supplier, ?string $trigger = null): ?Carbon
    {
        $at = SupplierEmail::query()->forShop($supplier->shop_id)->where('supplier_id', $supplier->id)
            ->when($trigger, fn ($q) => $q->where('trigger', $trigger))
            ->max('created_at');

        return $at ? Carbon::parse($at) : null;
    }
}
