<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentSupplierRepository implements SupplierRepositoryInterface
{
    public function allForShop(Shop $shop): Collection
    {
        return Supplier::query()->forShop($shop)->withCount(['variants' => fn ($q) => $q->where('is_active', true)])
            ->withMax('emails as last_emailed_at', 'created_at')
            ->orderBy('name')->get();
    }

    public function find(Shop $shop, int $id): ?Supplier
    {
        return Supplier::query()->forShop($shop)->find($id);
    }

    public function create(Shop $shop, array $attributes): Supplier
    {
        return Supplier::query()->create($attributes + ['shop_id' => $shop->id]);
    }

    public function update(Supplier $supplier, array $attributes): Supplier
    {
        $supplier->fill($attributes)->save();

        return $supplier;
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete(); // variants.supplier_id is nullOnDelete
    }
}
