<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Forecast\AccuracyReport;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;

/**
 * Forecast accuracy report (every plan): each week's forecast next to what really sold over the
 * following weeks, for the whole shop and per product. Part of the "every number can be checked"
 * promise. The first result appears once a weekly snapshot is `horizon_days` old.
 */
class ForecastAccuracyService
{
    private const TOP = 8;

    public function __construct(private readonly ForecastQueryRepositoryInterface $forecasts) {}

    public function report(Shop $shop): array
    {
        Entitlements::for($shop)->require(Feature::Accuracy);

        $horizon = (int) config('forecast.accuracy.horizon_days');
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $report = AccuracyReport::fromConfig();
        $weeks = $this->forecasts->snapshotWeeks($shop, $today->subDays($horizon)->toDateString(), (int) config('forecast.accuracy.weeks'));
        $base = ['horizon_days' => $horizon, 'min_in_stock_days' => (int) config('forecast.accuracy.min_in_stock_days')];

        if ($weeks === []) {
            // Still collecting: say when the first result comes.
            $all = $this->forecasts->snapshotWeeks($shop, $today->toDateString(), (int) config('forecast.accuracy.keep_weeks'));
            $first = $all === [] ? null : CarbonImmutable::parse(end($all))->addDays($horizon)->toDateString();

            return $base + ['available' => false, 'first_result_on' => $first, 'latest' => null, 'trend' => []];
        }

        $trend = [];
        foreach (array_reverse($weeks) as $week) {
            $s = $report->summarize($this->forecasts->accuracyRows($shop, $week, $horizon));
            $trend[] = ['week_start' => $week, 'products' => $s['products'], 'accuracy' => $s['accuracy'], 'bias' => $s['bias']];
        }

        $latest = $report->summarize($this->forecasts->accuracyRows($shop, $weeks[0], $horizon));
        $latest['week_start'] = $weeks[0];
        $latest['week_end'] = CarbonImmutable::parse($weeks[0])->addDays($horizon - 1)->toDateString();
        $latest['top_misses'] = array_slice($latest['items'], 0, self::TOP);
        unset($latest['items']);

        return $base + ['available' => true, 'first_result_on' => null, 'latest' => $latest, 'trend' => $trend];
    }

    /** The latest judged week of one product (product page), or null. */
    public function forVariant(Shop $shop, int $variantId): ?array
    {
        if (! Entitlements::for($shop)->has(Feature::Accuracy)) {
            return null;
        }
        $horizon = (int) config('forecast.accuracy.horizon_days');
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $report = AccuracyReport::fromConfig();

        foreach ($this->forecasts->snapshotWeeks($shop, $today->subDays($horizon)->toDateString(), 2) as $week) {
            $rows = $this->forecasts->accuracyRows($shop, $week, $horizon, $variantId);
            $item = $rows === [] ? null : $report->item($rows[0]);
            if ($item !== null) {
                return [
                    'week_start' => $week,
                    'week_end' => CarbonImmutable::parse($week)->addDays($horizon - 1)->toDateString(),
                    'predicted' => $item['predicted'],
                    'actual' => $item['actual'],
                    'in_stock_days' => $item['in_stock_days'],
                    'source' => $item['source'],
                ];
            }
        }

        return null;
    }
}
