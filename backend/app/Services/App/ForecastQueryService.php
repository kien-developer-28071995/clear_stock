<?php

namespace App\Services\App;

use App\Models\Forecast;
use App\Models\Shop;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ForecastQueryService
{
    public const PER_PAGE = 25;

    public function __construct(private readonly ForecastQueryRepositoryInterface $forecasts) {}

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
