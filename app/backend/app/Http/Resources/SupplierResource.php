<?php

namespace App\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'lead_time_days' => $this->lead_time_days,
            'min_order_qty' => $this->min_order_qty,
            'pack_size' => $this->pack_size,
            'order_cycle_days' => $this->order_cycle_days,
            'order_weekdays' => $this->order_weekdays,
            'landed_cost_percent' => $this->landed_cost_percent !== null ? (float) $this->landed_cost_percent : null,
            'min_order_value' => $this->min_order_value !== null ? (float) $this->min_order_value : null,
            // What is due to order from this supplier now ({products, units, cost at the supplier's price}); null = nothing.
            'due' => $request->attributes->get('supplier_due', [])[$this->id] ?? null,
            'auto_email' => $this->auto_email,
            'last_emailed_at' => $this->last_emailed_at ? Carbon::parse($this->last_emailed_at)->toIso8601String() : null,
            // Median days real deliveries took ({median_days, orders}); null with too few received orders.
            'actual_lead_time' => $request->attributes->get('actual_lead_times', [])[$this->id] ?? null,
            'variants_count' => $this->variants_count ?? null,
        ];
    }
}
