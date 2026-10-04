<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Models\Supplier;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Repositories\Contracts\ManualOrderRepositoryInterface;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    /** Fewer received orders than this say too little about a supplier's real lead time. */
    private const MIN_DELIVERIES = 3;

    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly ManualOrderRepositoryInterface $orders,
        private readonly ForecastQueryRepositoryInterface $forecasts,
    ) {}

    /**
     * How long deliveries really took: the median days from "marked as ordered" to "received"
     * over the last year, per supplier with enough received orders.
     *
     * @return array<int, array{median_days: int, orders: int}>
     */
    /**
     * What is due to reorder now per supplier, at the supplier's price: lets the list say when an
     * order would fall short of the supplier's minimum order value.
     *
     * @return array<int, array{products: int, units: int, cost: float}>
     */
    public function dueTotals(Shop $shop): array
    {
        $out = [];
        foreach ($this->forecasts->reorderList($shop, CarbonImmutable::now($shop->timezone)->toDateString(), null) as $f) {
            $id = $f->variant->supplier_id;
            if ($id === null || $f->variant->discontinued) {
                continue;
            }
            $out[$id] ??= ['products' => 0, 'units' => 0, 'cost' => 0.0];
            $out[$id]['products']++;
            $out[$id]['units'] += $f->suggested_qty;
            $out[$id]['cost'] = round($out[$id]['cost'] + $f->suggested_qty * (float) $f->variant->purchaseCost(), 2);
        }

        return $out;
    }

    public function actualLeadTimes(Shop $shop): array
    {
        $out = [];
        foreach ($this->orders->deliveryDays($shop, CarbonImmutable::now($shop->timezone)->subYear()->toDateString()) as $supplierId => $days) {
            if (count($days) < self::MIN_DELIVERIES) {
                continue;
            }
            sort($days);
            $mid = intdiv(count($days), 2);
            $median = count($days) % 2 === 1 ? $days[$mid] : (int) round(($days[$mid - 1] + $days[$mid]) / 2);
            $out[$supplierId] = ['median_days' => $median, 'orders' => count($days)];
        }

        return $out;
    }

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
        // Lead time and order rules feed the forecasts of the supplier's products.
        // (The landed cost share changes unit costs, applied at the start of the forecast run.)
        $changed = collect(['lead_time_days', 'min_order_qty', 'pack_size', 'order_cycle_days', 'order_weekdays', 'landed_cost_percent'])
            ->contains(fn ($field) => array_key_exists($field, $data) && $data[$field] != $supplier->{$field});
        $supplier = $this->suppliers->update($supplier, $data);

        if ($changed) {
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
            Entitlements::for($shop)->require(Feature::SupplierAutoEmail);
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
