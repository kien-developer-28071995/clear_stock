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
        $out = [
            'total' => $this->base($shop)->count(),
            'tracked' => Variant::query()->forShop($shop)->where('is_active', true)->where('tracked', true)->count(),
        ];
        foreach (ForecastStatus::cases() as $status) {
            $query = $this->base($shop);
            $this->whereStatus($query, $status, $today);
            $out[$status->value] = $query->count();
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
        $query = $this->base($shop);
        $this->whereStatus($query, ForecastStatus::Slow, '');
        $rows = $query->with('variant')->get(['forecasts.*']);

        $withCost = $rows->filter(fn (Forecast $f) => $f->variant->unit_cost !== null);
        $value = fn (Forecast $f) => round($f->current_stock * (float) $f->variant->unit_cost, 2);

        return [
            'value' => round($withCost->sum($value), 2),
            'count' => $rows->count(),
            'missing_cost' => $rows->count() - $withCost->count(),
            'top' => $withCost->sortByDesc($value)->take($limit)->map(fn (Forecast $f) => [
                'variant_id' => $f->variant_id,
                'name' => $f->variant->displayName(),
                'sku' => $f->variant->sku,
                'stock' => $f->current_stock,
                'value' => $value($f),
                'days_of_cover' => $f->days_of_cover !== null ? (float) $f->days_of_cover : null,
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
        $slowDays = (int) config('forecast.slow_mover_days');

        match ($status) {
            ForecastStatus::ReorderNow => $query->where('forecasts.reorder_date', '<=', $today),
            ForecastStatus::OutOfStock => $query->where('forecasts.current_stock', '<=', 0)->where('forecasts.avg_daily_sales', '>', 0),
            ForecastStatus::Slow => $query->where('forecasts.current_stock', '>', 0)
                ->where(fn ($q) => $q->where('forecasts.avg_daily_sales', '=', 0)->orWhere('forecasts.days_of_cover', '>', $slowDays)),
            ForecastStatus::Healthy => $query->where('forecasts.reorder_date', '>', $today)->where('forecasts.days_of_cover', '<=', $slowDays),
        };
    }
}
