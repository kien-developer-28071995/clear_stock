<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Forecast\ExplanationFormatter;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Home screen: a to-do list of what to reorder (out of stock, order today, this week),
 * a stock runway overview, and cash tied up in slow stock.
 */
class DashboardService
{
    /** Items loaded for the action list (the UI shows a few per group, "show more" for the rest). */
    private const ACTION_LIMIT = 60;

    private const RUNWAY_LIMIT = 12;

    public function __construct(
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly ExplanationFormatter $formatter,
    ) {}

    public function build(Shop $shop): array
    {
        $today = CarbonImmutable::now($shop->timezone);
        $todayYmd = $today->toDateString();
        $weekEnd = $today->addDays(7)->toDateString();
        $explain = Entitlements::for($shop)->has(Feature::Explanations);

        $groups = ['out_of_stock' => [], 'order_today' => [], 'this_week' => []];
        foreach ($this->forecasts->actionItems($shop, $weekEnd, self::ACTION_LIMIT) as $f) {
            $group = match (true) {
                $f->current_stock <= 0 => 'out_of_stock',
                $f->reorder_date !== null && $f->reorder_date->toDateString() <= $todayYmd => 'order_today',
                default => 'this_week',
            };
            $groups[$group][] = $this->item($f, $explain);
        }

        return [
            'today' => $todayYmd,
            'currency' => $shop->currency,
            'forecasted_at' => $shop->forecasted_at?->toIso8601String(),
            'counts' => $this->forecasts->counts($shop, $todayYmd),
            'actions' => $groups,
            'actions_truncated' => array_sum(array_map('count', $groups)) >= self::ACTION_LIMIT,
            'runway' => $this->forecasts->runway($shop, self::RUNWAY_LIMIT)->map(fn (Forecast $f) => [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'days_of_cover' => (float) $f->days_of_cover,
                // Days of stock needed to reorder in time (lead time + safety days).
                'reorder_days' => (int) (($f->explanation['lead_time']['days'] ?? 0) + ($f->explanation['safety']['days'] ?? 0)),
            ])->values()->all(),
            'slow_movers' => $this->forecasts->slowMovers($shop, 5) + ['days' => (int) config('forecast.slow_mover_days')],
            'explanations_locked' => ! $explain,
        ];
    }

    private function item(Forecast $f, bool $explain): array
    {
        $lines = $explain ? $this->formatter->lines($f->explanation) : [];

        return [
            'variant_id' => $f->variant_id,
            'name' => $f->variant->displayName(),
            'sku' => $f->variant->sku,
            'current_stock' => $f->current_stock,
            'avg_daily_sales' => (float) $f->avg_daily_sales,
            'days_of_cover' => $f->days_of_cover !== null ? (float) $f->days_of_cover : null,
            'stockout_date' => $f->stockout_date?->toDateString(),
            'reorder_date' => $f->reorder_date?->toDateString(),
            'suggested_qty' => $f->suggested_qty,
            'confidence' => $f->confidence->value,
            // Explanation lines as {code, params}; the app translates them.
            'reason' => $lines[0] ?? null,
            'explanation_lines' => $lines,
        ];
    }
}
