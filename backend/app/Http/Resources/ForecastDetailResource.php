<?php

namespace App\Http\Resources;

use App\Models\Forecast;
use App\Services\Forecast\ExplanationFormatter;
use Illuminate\Http\Request;

/** @mixin Forecast */
class ForecastDetailResource extends ForecastResource
{
    public function toArray(Request $request): array
    {
        $v = $this->variant;
        $shop = $request->attributes->get('shop_defaults', []);
        $explain = (bool) $request->attributes->get('explanations', false);

        return parent::toArray($request) + [
            // The reasoning is a paid feature; the numbers themselves are always shown.
            'explanation' => $explain ? $this->explanation : null,
            'explanation_sentences' => $explain ? app(ExplanationFormatter::class)->sentences($this->explanation) : [],
            'explanation_locked' => ! $explain,
            'computed_avg' => (float) ($this->explanation['computed_avg'] ?? $this->avg_daily_sales),
            // Growth: stock and forecast per location (forecast null when not computed there).
            'locations' => $this->locations($request, $explain),
            'overrides' => $v->overrides->mapWithKeys(fn ($o) => [$o->field->value => [
                'value' => (float) $o->value,
                'note' => $o->note,
                'expires_at' => $o->expires_at?->toDateString(),
            ]]),
            'settings' => [
                'supplier_id' => $v->supplier_id,
                'lead_time_override' => $v->lead_time_override,
                'safety_days' => $v->safety_days,
            ],
            'defaults' => $shop,
        ];
    }

    private function locations(Request $request, bool $explain): ?array
    {
        $locations = $request->attributes->get('by_location');
        if ($locations === null) {
            return null;
        }

        $formatter = app(ExplanationFormatter::class);

        return array_map(fn (array $l) => [
            'location_id' => $l['location_id'],
            'location' => $l['location'],
            'available' => $l['available'],
            'forecast' => $l['forecast'] === null ? null : [
                'avg_daily_sales' => (float) $l['forecast']->avg_daily_sales,
                'days_of_cover' => $l['forecast']->days_of_cover !== null ? (float) $l['forecast']->days_of_cover : null,
                'stockout_date' => $l['forecast']->stockout_date?->toDateString(),
                'reorder_date' => $l['forecast']->reorder_date?->toDateString(),
                'reorder_point' => $l['forecast']->reorder_point,
                'suggested_qty' => $l['forecast']->suggested_qty,
                'confidence' => $l['forecast']->confidence->value,
                'explanation_sentences' => $explain ? $formatter->sentences($l['forecast']->explanation) : [],
            ],
        ], $locations);
    }
}
