<?php

namespace App\Http\Resources;

use App\Enums\OverrideField;
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
            // {code, params} lines; the app turns them into sentences in its language.
            'explanation_lines' => $explain ? app(ExplanationFormatter::class)->lines($this->explanation) : [],
            'explanation_locked' => ! $explain,
            'computed_avg' => (float) ($this->explanation['computed_avg'] ?? $this->avg_daily_sales),
            // Growth: stock and forecast per location (forecast null when not computed there).
            'locations' => $this->locations($request, $explain),
            // Every field, null when not overridden (an empty list would serialize as [] instead of {}).
            'overrides' => collect(OverrideField::cases())->mapWithKeys(function (OverrideField $field) use ($v) {
                $o = $v->overrides->firstWhere('field', $field);

                return [$field->value => $o === null ? null : [
                    'value' => (float) $o->value,
                    'note' => $o->note,
                    'expires_at' => $o->expires_at?->toDateString(),
                ]];
            }),
            'settings' => [
                'supplier_id' => $v->supplier_id,
                'lead_time_override' => $v->lead_time_override,
                'safety_days' => $v->safety_days,
                'min_order_qty' => $v->min_order_qty,
                'pack_size' => $v->pack_size,
                'min_stock' => $v->min_stock,
                'max_stock' => $v->max_stock,
                'alerts_muted' => $v->alerts_muted,
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
                'incoming_stock' => $l['forecast']->incoming_stock,
                'avg_daily_sales' => (float) $l['forecast']->avg_daily_sales,
                'days_of_cover' => $l['forecast']->days_of_cover !== null ? (float) $l['forecast']->days_of_cover : null,
                'stockout_date' => $l['forecast']->stockout_date?->toDateString(),
                'reorder_date' => $l['forecast']->reorder_date?->toDateString(),
                'reorder_point' => $l['forecast']->reorder_point,
                'suggested_qty' => $l['forecast']->suggested_qty,
                'confidence' => $l['forecast']->confidence->value,
                'explanation_lines' => $explain ? $formatter->lines($l['forecast']->explanation) : [],
            ],
        ], $locations);
    }
}
