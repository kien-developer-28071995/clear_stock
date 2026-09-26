<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Exceptions\PlanRequiredException;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Support\Entitlements;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
        return $this->suppliers->create($shop, $this->withAutoEmail($shop, $data, null));
    }

    public function update(Shop $shop, Supplier $supplier, array $data): Supplier
    {
        $data = $this->withAutoEmail($shop, $data, $supplier);
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

    /**
     * Automatic purchase order emails are a Growth feature and need a supplier email;
     * removing the email turns them off.
     */
    private function withAutoEmail(Shop $shop, array $data, ?Supplier $supplier): array
    {
        $email = array_key_exists('email', $data) ? $data['email'] : $supplier?->email;
        if (! empty($data['auto_email'])) {
            if (! Entitlements::for($shop)->has(Feature::SupplierAutoEmail)) {
                throw new PlanRequiredException(Feature::SupplierAutoEmail);
            }
            if (! $email) {
                throw ValidationException::withMessages(['auto_email' => 'auto_email_needs_email']);
            }
        }
        if (! $email && ($supplier?->auto_email || array_key_exists('auto_email', $data))) {
            $data['auto_email'] = false;
        }

        return $data;
    }
}
