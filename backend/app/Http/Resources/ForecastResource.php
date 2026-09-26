<?php

namespace App\Http\Resources;

use App\Models\Forecast;
use App\Support\ForecastStatusResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the SKU list. The controller puts the shop's local date in the
 * request attribute "today" (used for the status badge).
 *
 * @mixin Forecast
 */
class ForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $v = $this->variant;

        return [
            'variant_id' => $this->variant_id,
            'name' => $v->displayName(),
            'product_title' => $v->product_title,
            'variant_title' => $v->title !== 'Default Title' ? $v->title : null,
            'sku' => $v->sku,
            'unit_cost' => $v->unit_cost !== null ? (float) $v->unit_cost : null,
            'supplier' => $v->relationLoaded('supplier') && $v->supplier ? ['id' => $v->supplier->id, 'name' => $v->supplier->name] : null,
            'is_bundle' => $v->is_bundle,
            'current_stock' => $this->current_stock,
            'incoming_stock' => $this->incoming_stock,     // on the way, already counted in suggested_qty
            'avg_daily_sales' => (float) $this->avg_daily_sales,
            'days_of_cover' => $this->days_of_cover !== null ? (float) $this->days_of_cover : null,
            'stockout_date' => $this->stockout_date?->toDateString(),
            'reorder_date' => $this->reorder_date?->toDateString(),
            'reorder_point' => $this->reorder_point,
            'suggested_qty' => $this->suggested_qty,
            'confidence' => $this->confidence->value,
            'status' => ForecastStatusResolver::for($this->resource, $request->attributes->get('today', now()->toDateString()))->value,
            'computed_at' => $this->computed_at->toIso8601String(),
        ];
    }
}
