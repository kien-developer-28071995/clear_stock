<?php

namespace App\Services\App;

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Support\Collection;

class SupplierService
{
    public function __construct(private readonly SupplierRepositoryInterface $suppliers) {}

    public function list(Shop $shop): Collection
    {
        return $this->suppliers->allForShop($shop);
    }

    public function find(Shop $shop, int $id): ?Supplier
    {
        return $this->suppliers->find($shop, $id);
    }

    public function create(Shop $shop, array $data): Supplier
    {
        return $this->suppliers->create($shop, $data);
    }

    public function update(Shop $shop, Supplier $supplier, array $data): Supplier
    {
        $leadChanged = array_key_exists('lead_time_days', $data) && $data['lead_time_days'] !== $supplier->lead_time_days;
        $supplier = $this->suppliers->update($supplier, $data);

        if ($leadChanged) {
            RecomputeForecasts::dispatch($shop->id);
        }

        return $supplier;
    }

    public function delete(Shop $shop, Supplier $supplier): void
    {
        $this->suppliers->delete($supplier);
        RecomputeForecasts::dispatch($shop->id);
    }
}
