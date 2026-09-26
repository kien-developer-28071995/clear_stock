<?php

namespace App\Repositories\Eloquent;

use App\Enums\ForecastStatus;
use App\Models\Forecast;
use App\Models\Shop;
use App\Models\Variant;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
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
            ->where('forecasts.avg_daily_sales', '>', 0)
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
            ->where('forecasts.avg_daily_sales', '>', 0)
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

        return match ($status) {
            ForecastStatus::ReorderNow => ['forecasts.reorder_date <= ?', [$today]],
            ForecastStatus::OutOfStock => ['(forecasts.current_stock <= 0 AND forecasts.avg_daily_sales > 0)', []],
            ForecastStatus::Slow => ['(forecasts.current_stock > 0 AND (forecasts.avg_daily_sales = 0 OR forecasts.days_of_cover > ?))', [$slowDays]],
            ForecastStatus::Overstock => ["({$upcoming} AND forecasts.current_stock > 0 AND {$overstock})", [$today, $slowDays, $ratio]],
            ForecastStatus::Healthy => ["({$upcoming} AND NOT {$overstock})", [$today, $slowDays, $ratio]],
        };
    }
}
