<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierEmail;
use Illuminate\Support\Carbon;

interface SupplierEmailRepositoryInterface
{
    /** @param array<int, array{variant_id: int, sku: ?string, name: string, quantity: int}> $items */
    public function log(Shop $shop, Supplier $supplier, string $trigger, string $to, ?string $replyTo, array $items): SupplierEmail;

    /** When this supplier was last emailed (by the given trigger, or any). */
    public function lastSentAt(Supplier $supplier, ?string $trigger = null): ?Carbon;
}
