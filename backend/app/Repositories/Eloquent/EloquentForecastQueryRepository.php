<?php

namespace App\Repositories\Eloquent;

use App\Enums\ForecastStatus;
use App\Models\Forecast;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentForecastQueryRepository implements ForecastQueryRepositoryInterface
{
    public function paginate(Shop $shop, array $filters, string $today, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->base($shop, $filters['location_id'] ?? null)->with('variant.supplier');

        if ($status = ForecastStatus::tryFrom((string) ($filters['status'] ?? ''))) {
            $this->whereStatus($query, $status, $today);
        }

        if (in_array($filters['abc'] ?? '', ['A', 'B', 'C'], true)) {
            $query->where('variants.abc_class', $filters['abc']);
        }

        // Selling clearly faster / slower than in the weeks before.
        $threshold = (int) round((float) config('forecast.trend.threshold') * 100);
        match ($filters['trend'] ?? null) {
            'up' => $query->where('forecasts.trend_percent', '>=', $threshold),
            'down' => $query->where('forecasts.trend_percent', '<=', -$threshold),
            default => null,
        };

        foreach (['vendor', 'product_type'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where("variants.{$field}", $filters[$field]);
            }
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn ($q) => $q->where('variants.product_title', 'like', $like)
                ->orWhere('variants.title', 'like', $like)
                ->orWhere('variants.sku', 'like', $like));
        }

        match ($filters['sort'] ?? 'urgency') {
            'name' => $query->orderBy('variants.product_title')->orderBy('variants.title'),
            'cover' => $query->orderByRaw('forecasts.days_of_cover IS NULL')->orderBy('forecasts.days_of_cover'),
            'suggested' => $query->orderByDesc('forecasts.suggested_qty'),
            'value' => $query->orderByRaw('(forecasts.current_stock * COALESCE(variants.unit_cost, 0)) DESC'),
            'revenue' => $query->orderByDesc('variants.revenue_90d'),
            default => $query->orderByRaw('forecasts.reorder_date IS NULL')->orderBy('forecasts.reorder_date')
                ->orderByRaw('forecasts.stockout_date IS NULL')->orderBy('forecasts.stockout_date'),
        };

        return $query->orderBy('forecasts.id')->paginate($perPage, ['forecasts.*'], 'page', $page);
    }

    public function findForVariant(Shop $shop, int $variantId): ?Forecast
    {
        return $this->base($shop)->where('forecasts.variant_id', $variantId)
            ->with(['variant.supplier', 'variant.overrides' => fn ($q) => $q->active()])
            ->first(['forecasts.*']);
    }

    public function byLocation(Shop $shop, int $variantId): array
    {
        $forecasts = Forecast::query()->withoutGlobalScope('shop')->where('shop_id', $shop->id)
            ->where('variant_id', $variantId)->whereNotNull('location_id')->get()->keyBy('location_id');

        return DB::table('locations')
            ->leftJoin('inventory_levels', fn ($j) => $j->on('inventory_levels.location_id', '=', 'locations.id')->where('inventory_levels.variant_id', $variantId))
            ->where('locations.shop_id', $shop->id)->where('locations.is_active', true)
            ->orderBy('locations.name')
            ->get(['locations.id', 'locations.name', 'inventory_levels.available'])
            ->filter(fn ($r) => $r->available !== null || $forecasts->has($r->id))
            ->map(fn ($r) => [
                'location_id' => (int) $r->id,
                'location' => $r->name,
                'available' => (int) ($r->available ?? 0),
                'forecast' => $forecasts->get($r->id),
            ])->values()->all();
    }

    public function activeLocations(Shop $shop): array
    {
        return DB::table('locations')->where('shop_id', $shop->id)->where('is_active', true)->orderBy('name')
            ->get(['id', 'name'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all();
    }

    public function reorderList(Shop $shop, string $today, ?int $supplierId, ?int $locationId = null, ?array $variantIds = null): Collection
    {
        $query = $this->base($shop, $locationId)->with(['variant.supplier', 'location'])->where('forecasts.suggested_qty', '>', 0);
        if ($variantIds !== null) {
            $query->whereIn('forecasts.variant_id', $variantIds);
        } else {
            $this->whereStatus($query, ForecastStatus::ReorderNow, $today);
        }
        if ($supplierId !== null) {
            $query->where('variants.supplier_id', $supplierId);
        }

        return $query->orderBy('variants.product_title')->orderBy('variants.title')->get(['forecasts.*']);
    }

    public function lowCover(Shop $shop, string $today, int $days): Collection
    {
        return $this->base($shop)->with('variant')
            ->where('forecasts.avg_daily_sales', '>', 0)->where('variants.discontinued', false)
            ->where('forecasts.current_stock', '>', 0)->where('forecasts.days_of_cover', '<=', $days)
            ->where(fn ($q) => $q->whereNull('forecasts.reorder_date')->orWhere('forecasts.reorder_date', '>', $today))
            ->orderBy('forecasts.days_of_cover')->orderBy('forecasts.id')->get(['forecasts.*']);
    }

    public function counts(Shop $shop, string $today): array
    {
        // One query: every status is a conditional count over the same rows.
        $selects = ['COUNT(*) as total'];
        $bindings = [];
        foreach (ForecastStatus::cases() as $status) {
            [$sql, $params] = $this->statusSql($status, $today);
            $selects[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as {$status->value}";
            array_push($bindings, ...$params);
        }
        $row = $this->base($shop)->selectRaw(implode(', ', $selects), $bindings)->toBase()->first();

        $out = [
            'total' => (int) $row->total,
            'tracked' => Variant::query()->forShop($shop)->where('is_active', true)->where('tracked', true)->count(),
        ];
        foreach (ForecastStatus::cases() as $status) {
            $out[$status->value] = (int) ($row->{$status->value} ?? 0);
        }

        return $out;
    }

    public function actionItems(Shop $shop, string $until, int $limit): Collection
    {
        return $this->base($shop)->with('variant')
            ->where('forecasts.avg_daily_sales', '>', 0)->where('variants.discontinued', false)
            ->where(fn ($q) => $q->where('forecasts.current_stock', '<=', 0)->orWhere('forecasts.reorder_date', '<=', $until))
            ->orderByRaw('forecasts.current_stock > 0')
            ->orderBy('forecasts.reorder_date')
            ->orderBy('forecasts.stockout_date')
            ->orderByDesc('forecasts.avg_daily_sales')
            ->limit($limit)->get(['forecasts.*']);
    }

    public function runway(Shop $shop, int $limit): Collection
    {
        return $this->base($shop)->with('variant')
            ->where('forecasts.avg_daily_sales', '>', 0)->where('variants.discontinued', false)
            ->orderBy('forecasts.days_of_cover')
            ->orderByDesc('forecasts.avg_daily_sales')
            ->limit($limit)->get(['forecasts.*']);
    }

    public function slowMovers(Shop $shop, int $limit): array
    {
        // Totals in SQL: a shop can have thousands of slow products.
        $value = 'forecasts.current_stock * variants.unit_cost';
        $sum = $this->statusQuery($shop, ForecastStatus::Slow, '')
            ->selectRaw("COUNT(*) as n, SUM(CASE WHEN variants.unit_cost IS NULL THEN 1 ELSE 0 END) as missing, COALESCE(SUM({$value}), 0) as value")
            ->toBase()->first();
        $top = $this->statusQuery($shop, ForecastStatus::Slow, '')->whereNotNull('variants.unit_cost')
            ->with('variant')->orderByRaw("{$value} DESC")->orderBy('forecasts.id')->limit($limit)->get(['forecasts.*']);

        return [
            'value' => round((float) $sum->value, 2),
            'count' => (int) $sum->n,
            'missing_cost' => (int) $sum->missing,
            'top' => $top->map(fn (Forecast $f) => [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'stock' => $f->current_stock,
                'value' => round($f->current_stock * (float) $f->variant->unit_cost, 2),
                'days_of_cover' => $f->days_of_cover !== null ? (float) $f->days_of_cover : null,
            ])->values()->all(),
        ];
    }

    public function overstock(Shop $shop, string $today, int $limit): array
    {
        $value = 'forecasts.excess_units * variants.unit_cost';
        $sum = $this->statusQuery($shop, ForecastStatus::Overstock, $today)
            ->selectRaw("COUNT(*) as n, COALESCE(SUM(forecasts.excess_units), 0) as units, SUM(CASE WHEN variants.unit_cost IS NULL THEN 1 ELSE 0 END) as missing, COALESCE(SUM({$value}), 0) as value")
            ->toBase()->first();
        $top = $this->statusQuery($shop, ForecastStatus::Overstock, $today)->with('variant')
            ->orderByRaw("COALESCE({$value}, 0) DESC")->orderByDesc('forecasts.excess_units')->orderBy('forecasts.id')
            ->limit($limit)->get(['forecasts.*']);

        return [
            'value' => round((float) $sum->value, 2),
            'units' => (int) $sum->units,
            'count' => (int) $sum->n,
            'missing_cost' => (int) $sum->missing,
            'top' => $top->map(fn (Forecast $f) => [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'stock' => $f->current_stock,
                'target' => $f->target_stock,
                'excess' => $f->excess_units,
                'value' => $f->variant->unit_cost !== null ? round($f->excess_units * (float) $f->variant->unit_cost, 2) : null,
            ])->values()->all(),
        ];
    }

    public function lostSales(Shop $shop, int $limit): array
    {
        $revenue = 'forecasts.lost_units_30d * variants.price';
        $query = fn () => $this->base($shop)->where('forecasts.lost_units_30d', '>', 0);
        $sum = $query()
            ->selectRaw("COUNT(*) as n, COALESCE(SUM(forecasts.lost_units_30d), 0) as units, SUM(CASE WHEN variants.price IS NULL THEN 1 ELSE 0 END) as missing, COALESCE(SUM({$revenue}), 0) as revenue")
            ->toBase()->first();
        $top = $query()->with('variant')
            ->orderByRaw("COALESCE({$revenue}, 0) DESC")->orderByDesc('forecasts.lost_units_30d')->orderBy('forecasts.id')
            ->limit($limit)->get(['forecasts.*']);

        return [
            'units' => round((float) $sum->units, 1),
            'revenue' => round((float) $sum->revenue, 2),
            'count' => (int) $sum->n,
            'missing_price' => (int) $sum->missing,
            'top' => $top->map(fn (Forecast $f) => [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'stock' => $f->current_stock,
                'out_of_stock_days' => (int) ($f->explanation['lost_sales']['out_of_stock_days'] ?? 0),
                'units' => (float) $f->lost_units_30d,
                'revenue' => $f->variant->price !== null ? round((float) $f->lost_units_30d * (float) $f->variant->price, 2) : null,
            ])->values()->all(),
        ];
    }

    public function planningRows(Shop $shop, array $filters): iterable
    {
        // Discontinued products are never ordered again: no plan, scenario or Flow trigger.
        $query = $this->base($shop)->with('variant.supplier')->where('variants.discontinued', false)
            ->when(($filters['supplier_id'] ?? null) !== null, fn ($q) => $q->where('variants.supplier_id', $filters['supplier_id']))
            ->when(($filters['vendor'] ?? '') !== '', fn ($q) => $q->where('variants.vendor', $filters['vendor']))
            ->when(in_array($filters['abc'] ?? '', ['A', 'B', 'C'], true), fn ($q) => $q->where('variants.abc_class', $filters['abc']))
            ->select('forecasts.*')->orderBy('forecasts.id');

        foreach ($query->lazy(500) as $f) {
            /** @var Forecast $f */
            $v = $f->variant;
            $e = $f->explanation;

            yield [
                'variant_id' => $f->variant_id,
                'shopify_product_id' => $v->shopify_product_id,
                'shopify_variant_id' => $v->shopify_variant_id,
                'name' => $v->displayName(),
                'sku' => $v->sku,
                'abc_class' => $v->abc_class,
                'supplier_id' => $v->supplier_id,
                'supplier' => $v->supplier?->name,
                'supplier_email' => $v->supplier?->email,
                'supplier_lead_time_days' => $v->supplier?->lead_time_days,
                'unit_cost' => $v->unit_cost !== null ? (float) $v->unit_cost : null,
                'as_of' => (string) ($e['as_of'] ?? $f->computed_at->toDateString()),
                'avg' => (float) $f->avg_daily_sales,
                'stock' => $f->current_stock,
                'incoming' => $f->incoming_stock,
                'lead_time_days' => (int) ($e['lead_time']['days'] ?? 0),
                'safety_days' => (int) ($e['safety']['days'] ?? 0),
                'min_stock' => $v->min_stock,
                'max_stock' => $v->max_stock,
                'min_order_qty' => $v->effectiveMinOrderQty(),
                'pack_size' => $v->effectivePackSize(),
                'order_cycle_days' => $v->supplier?->order_cycle_days,
                'order_weekdays' => $v->supplier?->order_weekdays,
                // The stored forecast itself (Flow triggers).
                'reorder_date' => $f->reorder_date?->toDateString(),
                'stockout_date' => $f->stockout_date?->toDateString(),
                'suggested_qty' => $f->suggested_qty,
                'confidence' => $f->confidence->value,
            ];
        }
    }

    public function abcSummary(Shop $shop): array
    {
        $rows = $this->base($shop)->groupBy('variants.abc_class')
            ->selectRaw('variants.abc_class as abc, COUNT(*) as n, COALESCE(SUM(variants.revenue_90d), 0) as revenue, '
                .'COALESCE(SUM(variants.revenue_share), 0) as share, '
                .'COALESCE(SUM(CASE WHEN forecasts.current_stock > 0 THEN forecasts.current_stock * variants.unit_cost ELSE 0 END), 0) as stock_value, '
                .'SUM(CASE WHEN variants.unit_cost IS NULL AND forecasts.current_stock > 0 THEN 1 ELSE 0 END) as missing')
            ->toBase()->get()->keyBy(fn ($r) => $r->abc ?? '');

        $classes = [];
        foreach (['A', 'B', 'C'] as $class) {
            $r = $rows->get($class);
            $classes[$class] = [
                'count' => (int) ($r->n ?? 0),
                'revenue' => round((float) ($r->revenue ?? 0), 2),
                'revenue_share' => round((float) ($r->share ?? 0), 4),
                'stock_value' => round((float) ($r->stock_value ?? 0), 2),
            ];
        }

        return [
            'classes' => $classes,
            'unclassified' => (int) ($rows->get('')->n ?? 0),
            'missing_cost' => (int) $rows->sum(fn ($r) => (int) $r->missing),
        ];
    }

    public function discontinuedStock(Shop $shop): array
    {
        $row = $this->statusQuery($shop, ForecastStatus::Discontinued, '')->where('forecasts.current_stock', '>', 0)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(forecasts.current_stock), 0) as units, '
                .'COALESCE(SUM(forecasts.current_stock * variants.unit_cost), 0) as value, '
                .'SUM(CASE WHEN variants.unit_cost IS NULL THEN 1 ELSE 0 END) as missing')
            ->toBase()->first();

        return ['count' => (int) $row->n, 'units' => (int) $row->units, 'value' => round((float) $row->value, 2), 'missing_cost' => (int) $row->missing];
    }

    public function previousSnapshot(Shop $shop, int $variantId, string $weekStart): ?array
    {
        $row = DB::table('forecast_snapshots')->where('shop_id', $shop->id)->where('variant_id', $variantId)
            ->where('week_start', '<', $weekStart)->orderByDesc('week_start')->first(['week_start', 'avg_daily_sales']);

        return $row === null ? null : ['week_start' => substr((string) $row->week_start, 0, 10), 'avg' => (float) $row->avg_daily_sales];
    }

    public function snapshotWeeks(Shop $shop, string $latestStart, int $limit): array
    {
        return DB::table('forecast_snapshots')->where('shop_id', $shop->id)->where('week_start', '<=', $latestStart)
            ->distinct()->orderByDesc('week_start')->limit($limit)->pluck('week_start')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }

    public function accuracyRows(Shop $shop, string $weekStart, int $horizonDays, ?int $variantId = null): array
    {
        $to = CarbonImmutable::parse($weekStart)->addDays($horizonDays - 1)->toDateString();

        return DB::table('forecast_snapshots as s')
            ->join('variants as v', 'v.id', '=', 's.variant_id')
            ->leftJoin('daily_sales as d', fn ($j) => $j->on('d.variant_id', '=', 's.variant_id')->whereBetween('d.date', [$weekStart, $to]))
            ->where('s.shop_id', $shop->id)->where('s.week_start', $weekStart)->where('s.has_bundles', false)
            ->where('v.is_active', true)
            ->when($variantId !== null, fn ($q) => $q->where('s.variant_id', $variantId))
            ->groupBy('s.variant_id', 's.avg_daily_sales', 's.avg_source', 'v.product_title', 'v.title', 'v.sku')
            ->selectRaw('s.variant_id, s.avg_daily_sales, s.avg_source, v.product_title, v.title, v.sku, '
                // Net units per day, never negative (same as the forecast's demand).
                .'COALESCE(SUM(CASE WHEN d.was_in_stock = 1 AND d.units_sold > d.units_returned THEN d.units_sold - d.units_returned ELSE 0 END), 0) as units, '
                .'COALESCE(SUM(CASE WHEN d.was_in_stock = 0 THEN 1 ELSE 0 END), 0) as oos_days')
            ->orderBy('s.variant_id')
            ->get()->map(fn ($r) => [
                'variant_id' => (int) $r->variant_id,
                'name' => $r->title && $r->title !== 'Default Title' ? "{$r->product_title} - {$r->title}" : $r->product_title,
                'sku' => $r->sku,
                'predicted' => (float) $r->avg_daily_sales,
                'source' => $r->avg_source,
                'units' => (int) $r->units,
                'oos_days' => (int) $r->oos_days,
            ])->all();
    }

    /**
     * Forecasts of active variants of this shop, joined to variants for search/sort.
     * Combined (all locations) by default, or one location's forecasts.
     */
    private function base(Shop $shop, ?int $locationId = null): Builder
    {
        return Forecast::query()->withoutGlobalScope('shop')
            ->join('variants', 'variants.id', '=', 'forecasts.variant_id')
            ->where('forecasts.shop_id', $shop->id)
            ->when($locationId !== null, fn ($q) => $q->where('forecasts.location_id', $locationId), fn ($q) => $q->whereNull('forecasts.location_id'))
            ->where('variants.is_active', true);
    }

    private function whereStatus(Builder $query, ForecastStatus $status, string $today): void
    {
        [$sql, $bindings] = $this->statusSql($status, $today);
        $query->whereRaw($sql, $bindings);
    }

    private function statusQuery(Shop $shop, ForecastStatus $status, string $today): Builder
    {
        $query = $this->base($shop);
        $this->whereStatus($query, $status, $today);

        return $query;
    }

    /**
     * The status rules as one SQL condition, used for filters and counts alike (same rules
     * as ForecastStatusResolver for a single row). @return array{0: string, 1: array<int, mixed>}
     */
    private function statusSql(ForecastStatus $status, string $today): array
    {
        $slowDays = (int) config('forecast.slow_mover_days');
        $ratio = (float) config('forecast.overstock_ratio');
        $overstock = '(forecasts.avg_daily_sales > 0 AND forecasts.target_stock > 0 AND forecasts.excess_units > forecasts.target_stock * ?)';
        $upcoming = 'forecasts.reorder_date > ? AND forecasts.days_of_cover <= ?';

        // Discontinued products have a status of their own and none of the others.
        [$sql, $bindings] = match ($status) {
            ForecastStatus::Discontinued => ['variants.discontinued = 1', []],
            ForecastStatus::ReorderNow => ['forecasts.reorder_date <= ?', [$today]],
            ForecastStatus::OutOfStock => ['(forecasts.current_stock <= 0 AND forecasts.avg_daily_sales > 0)', []],
            ForecastStatus::Slow => ['(forecasts.current_stock > 0 AND (forecasts.avg_daily_sales = 0 OR forecasts.days_of_cover > ?))', [$slowDays]],
            ForecastStatus::Overstock => ["({$upcoming} AND forecasts.current_stock > 0 AND {$overstock})", [$today, $slowDays, $ratio]],
            ForecastStatus::Healthy => ["({$upcoming} AND NOT {$overstock})", [$today, $slowDays, $ratio]],
        };

        return $status === ForecastStatus::Discontinued ? [$sql, $bindings] : ["(variants.discontinued = 0 AND {$sql})", $bindings];
    }
}
