<?php

namespace App\Services\App;

use App\Models\Forecast;
use App\Models\Shop;
use App\Support\ForecastStatusResolver;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ForecastQueryService
{
    public const PER_PAGE = 25;

    private const EXPORT_CHUNK = 500;

    public function __construct(private readonly ForecastQueryRepositoryInterface $forecasts) {}

    /**
     * Every product matching the list filters, as CSV rows (header first). Read page by page:
     * a shop can have tens of thousands of products.
     *
     * @return \Generator<int, array<int, string|int|float|null>>
     */
    public function exportRows(Shop $shop, array $filters): \Generator
    {
        $today = $this->today($shop);
        yield ['Product', 'SKU', 'Vendor', 'Supplier', 'Status', 'ABC', 'In stock', 'On the way', 'Sells per day', 'Trend %', 'Days of stock', 'Runs out', 'Order by', 'Suggested order', 'Unit cost', 'Stock value', 'Currency'];

        $page = 1;
        do {
            $result = $this->forecasts->paginate($shop, $filters, $today, self::EXPORT_CHUNK, $page);
            foreach ($result->getCollection() as $f) {
                $v = $f->variant;
                $cost = $v->unit_cost !== null ? (float) $v->unit_cost : null;
                yield [
                    $v->displayName(), $v->sku ?? '', $v->vendor ?? '', $v->supplier?->name ?? '',
                    ForecastStatusResolver::for($f, $today)->value, $v->abc_class ?? '',
                    $f->current_stock, $f->incoming_stock, (float) $f->avg_daily_sales, $f->trend_percent,
                    $f->days_of_cover !== null ? (float) $f->days_of_cover : null,
                    $f->stockout_date?->toDateString() ?? '', $f->reorder_date?->toDateString() ?? '', $f->suggested_qty,
                    $cost, $cost !== null ? round(max(0, $f->current_stock) * $cost, 2) : null, $shop->currency,
                ];
            }
        } while ($page++ < $result->lastPage());
    }

    public function list(Shop $shop, array $filters, int $page): LengthAwarePaginator
    {
        return $this->forecasts->paginate($shop, $filters, $this->today($shop), self::PER_PAGE, max(1, $page));
    }

    public function detail(Shop $shop, int $variantId): ?Forecast
    {
        return $this->forecasts->findForVariant($shop, $variantId);
    }

    /** @return array<int, array{location_id: int, location: string, available: int, forecast: ?Forecast}> */
    public function byLocation(Shop $shop, int $variantId): array
    {
        return $this->forecasts->byLocation($shop, $variantId);
    }

    /** @return array<int, array{id: int, name: string}> */
    public function locations(Shop $shop): array
    {
        return $this->forecasts->activeLocations($shop);
    }

    public function today(Shop $shop): string
    {
        return CarbonImmutable::now($shop->timezone)->toDateString();
    }
}
