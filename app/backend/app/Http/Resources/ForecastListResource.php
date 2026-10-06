<?php

namespace App\Http\Resources;

use App\Enums\Feature;
use App\Models\Forecast;
use App\Support\Features;
use App\Support\ForecastStatusResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the product list: only the columns the list shows. The product page
 * uses ForecastDetailResource (everything, with the explanation).
 *
 * @mixin Forecast
 */
class ForecastListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $v = $this->variant;

        return [
            'variant_id' => $this->variant_id,
            'name' => $v->displayName(),
            'sku' => $v->sku,
            'vendor' => $v->vendor,
            'abc_class' => Features::enabled(Feature::Abc) ? $v->abc_class : null,
            'status' => ForecastStatusResolver::for($this->resource, $request->attributes->get('today', now()->toDateString()))->value,
            'current_stock' => $this->current_stock,
            'incoming_stock' => $this->incoming_stock,
            'avg_daily_sales' => (float) $this->avg_daily_sales,
            'days_of_cover' => $this->days_of_cover !== null ? (float) $this->days_of_cover : null,
            'reorder_date' => $this->reorder_date?->toDateString(),
            'suggested_qty' => $this->suggested_qty,
            'excess_units' => $this->excess_units,
            'trend_percent' => $this->trend_percent,
        ];
    }
}
