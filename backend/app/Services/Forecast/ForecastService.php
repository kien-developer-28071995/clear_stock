<?php

namespace App\Services\Forecast;

use App\Enums\Feature;
use App\Events\ForecastsUpdated;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\CostRepositoryInterface;
use App\Repositories\Contracts\DailySalesRepositoryInterface;
use App\Repositories\Contracts\ForecastRepositoryInterface;
use App\Repositories\Contracts\InventorySnapshotRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Sync\LocationSupport;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/** Recomputes the combined (all-locations) forecast of every tracked variant of a shop. */
class ForecastService
{
    private const BATCH = 200;

    public function __construct(
        private readonly ForecastRepositoryInterface $forecasts,
        private readonly CatalogRepositoryInterface $catalog,
        private readonly ShopRepositoryInterface $shops,
        private readonly ForecastInputBuilder $inputs,
        private readonly ForecastCalculator $calculator,
        private readonly DailySalesRepositoryInterface $sales,
        private readonly LocationSupport $locationSupport,
        private readonly InventorySnapshotRepositoryInterface $inventorySnapshots,
        private readonly CostRepositoryInterface $costs,
    ) {}

    /**
     * @param  array<int, int>|null  $onlyVariantIds  recompute just these (e.g. after the merchant edits a setting)
     * @return array{variants: int, reorder_now: int, low_confidence: int, removed: int, not_forecasted: int}
     */
    public function runForShop(Shop $shop, ?array $onlyVariantIds = null, ?CarbonImmutable $now = null): array
    {
        $asOf = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone)->startOfDay();
        $computedAt = now();

        // Supplier and cost changes all end in a forecast run: unit costs follow the suppliers' landed cost share here.
        $this->costs->applyLandedCosts($shop);

        $ids = $this->forecasts->forecastableVariantIds($shop);
        $notForecasted = 0;

        // ABC classes first: the forecast writes below bump the cache version, so no list is cached
        // with the old classes. Every tracked product is ranked, also beyond the Free plan's limit.
        if ($onlyVariantIds === null) {
            $this->classifyAbc($shop, $ids, $asOf);
        }

        // Free plan: the best-selling products up to the plan's SKU limit. Discontinued products
        // (no longer reordered) are forecast on top of it: they only sell through.
        $discontinued = array_values(array_intersect($ids, $this->forecasts->discontinuedVariantIds($shop)));
        $max = Entitlements::for($shop)->maxSkus();
        if ($max !== null && count($ids) - count($discontinued) > $max) {
            $active = array_values(array_diff($ids, $discontinued));
            $notForecasted = count($active) - $max;
            $ids = [...$this->sales->topSellers($shop, $active, $asOf->subDays(90)->toDateString(), $max), ...$discontinued];
        }

        if ($onlyVariantIds !== null) {
            $ids = array_values(array_intersect($ids, $onlyVariantIds));
        }
        $stock = $this->catalog->stockByVariant($shop);
        $incoming = $this->catalog->incomingByVariant($shop);
        $byLocation = $this->locationSupport->enabled($shop);
        $locationStock = $byLocation ? $this->catalog->stockByVariantAndLocation($shop) : [];
        $locationIncoming = $byLocation ? $this->catalog->incomingByVariantAndLocation($shop) : [];

        $stats = ['variants' => 0, 'reorder_now' => 0, 'low_confidence' => 0, 'removed' => 0, 'not_forecasted' => $notForecasted, 'location_forecasts' => 0];

        $snapshots = [];
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $rows = [];
            $inputs = $this->inputs->build($shop, $batch, $stock, $asOf, $incoming);
            foreach ($inputs as $input) {
                $r = $this->calculator->calculate($input);
                $rows[] = $this->row($r, $computedAt);
                $snapshots[] = [
                    'variant_id' => $r->variantId,
                    'avg_daily_sales' => $r->avgDailySales,
                    'avg_source' => $r->explanation['avg_source'],
                    'has_bundles' => $r->explanation['bundles'] !== [],
                ];
                $stats['variants']++;
                $stats['reorder_now'] += $r->reorderDate !== null && $r->reorderDate <= $asOf->toDateString() ? 1 : 0;
                $stats['low_confidence'] += $r->confidence->value === 'low' ? 1 : 0;
            }
            $this->forecasts->replaceForecasts($shop, $rows);

            // Growth: the same forecast per fulfilling location.
            if ($byLocation) {
                $locationRows = [];
                foreach ($this->inputs->buildForLocations($shop, $batch, $locationStock, $asOf, $inputs, $locationIncoming) as $item) {
                    $locationRows[] = $this->row($this->calculator->calculate($item['input']), $computedAt) + ['location_id' => $item['location_id']];
                }
                $this->forecasts->replaceLocationForecasts($shop, $batch, $locationRows);
                $stats['location_forecasts'] += count($locationRows);
            }
        }

        if (! $byLocation && $onlyVariantIds === null) {
            $this->forecasts->deleteLocationForecasts($shop); // e.g. after a downgrade
        }

        if ($onlyVariantIds === null) {
            // Variants no longer active/tracked keep no stale forecast.
            $stats['removed'] = $this->forecasts->deleteForecastsExcept($shop, $ids);
            $this->saveSnapshots($shop, $asOf, $snapshots);
            // Today's stock units and value, for the inventory value history.
            $this->inventorySnapshots->record($shop, $asOf->toDateString(), $stock);
            $this->inventorySnapshots->prune($shop, $asOf->subDays((int) config('forecast.stock_history_days'))->toDateString());
            $shop = $this->shops->update($shop, ['forecasted_at' => $computedAt]);
        }

        Log::info('Forecasts computed', ['shop' => $shop->domain] + $stats);
        ForecastsUpdated::dispatch($shop, $stats);

        return $stats;
    }

    /** This week's rates, for the accuracy report (weeks start on Monday, shop time). */
    private function saveSnapshots(Shop $shop, CarbonImmutable $asOf, array $rows): void
    {
        $this->forecasts->saveWeeklySnapshots($shop, $asOf->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $rows);
        $this->forecasts->pruneSnapshots($shop, $asOf->subWeeks((int) config('forecast.accuracy.keep_weeks'))->toDateString());
    }

    /**
     * Bundles (paid plans): a virtual bundle's revenue counts toward the products inside it,
     * so a component that mostly sells in bundles is still ranked by what it earns.
     *
     * @param  array<int, int>  $ids
     */
    private function classifyAbc(Shop $shop, array $ids, CarbonImmutable $asOf): void
    {
        $from = $asOf->subDays((int) config('forecast.abc.days'))->toDateString();
        $revenue = $this->forecasts->revenueSince($shop, $ids, $from);
        if (Entitlements::for($shop)->has(Feature::Bundles)) {
            ['bundles' => $bundles, 'prices' => $prices] = $this->forecasts->bundlesWithComponents($shop, $ids);
            if ($bundles !== []) {
                $revenue = BundleRevenue::attribute($revenue, $bundles, $this->forecasts->revenueSince($shop, array_keys($bundles), $from), $prices, $ids);
            }
        }
        $this->forecasts->saveAbcClasses($shop, AbcClassifier::fromConfig()->classify($revenue));
    }

    private function row(ForecastResult $r, \DateTimeInterface $computedAt): array
    {
        return [
            'variant_id' => $r->variantId,
            'current_stock' => $r->currentStock,
            'incoming_stock' => $r->incomingStock,
            'avg_daily_sales' => $r->avgDailySales,
            'days_of_cover' => $r->daysOfCover,
            'stockout_date' => $r->stockoutDate,
            'reorder_date' => $r->reorderDate,
            'reorder_point' => $r->reorderPoint,
            'suggested_qty' => $r->suggestedQty,
            'target_stock' => $r->targetStock,
            'excess_units' => $r->excessUnits,
            'lost_units_30d' => $r->lostUnits30d,
            'trend_percent' => $r->trendPercent,
            'confidence' => $r->confidence->value,
            'explanation' => json_encode($r->explanation, JSON_THROW_ON_ERROR),
            'computed_at' => $computedAt,
        ];
    }
}
