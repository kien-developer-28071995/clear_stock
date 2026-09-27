<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\SalesEvent;
use App\Models\Shop;
use App\Repositories\Contracts\SalesEventRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\Forecast\SalesEventMatcher;
use App\Support\Entitlements;
use App\Support\Features;
use App\Support\Gid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sales events: promotions and other known changes in sales that the merchant enters. Every change
 * recomputes the shop's forecasts (upcoming events feed orders, past ones are normalised in history).
 */
class SalesEventService
{
    public function __construct(
        private readonly SalesEventRepositoryInterface $events,
        private readonly VariantRepositoryInterface $variants,
    ) {}

    /** @return Collection<int, SalesEvent> */
    public function list(Shop $shop): Collection
    {
        Entitlements::for($shop)->require(Feature::SalesEvents);

        return $this->events->allForShop($shop);
    }

    public function find(Shop $shop, int $id): ?SalesEvent
    {
        return $this->events->find($shop, $id);
    }

    public function create(Shop $shop, array $data): SalesEvent
    {
        Entitlements::for($shop)->require(Feature::SalesEvents);
        $event = $this->events->create($shop, $this->attributes($shop, $data));
        RecomputeForecasts::dispatch($shop->id);

        return $event;
    }

    public function update(Shop $shop, SalesEvent $event, array $data): SalesEvent
    {
        Entitlements::for($shop)->require(Feature::SalesEvents);
        $event = $this->events->update($event, $this->attributes($shop, $data));
        RecomputeForecasts::dispatch($shop->id);

        return $event;
    }

    public function delete(Shop $shop, SalesEvent $event): void
    {
        $this->events->delete($event);
        RecomputeForecasts::dispatch($shop->id);
    }

    /**
     * Events a forecast made today can use: past ones inside the sales history and upcoming ones.
     * No events when the feature is switched off.
     */
    public function matcher(Shop $shop, ?CarbonImmutable $asOf = null): SalesEventMatcher
    {
        if (! Features::enabled(Feature::SalesEvents)) {
            return SalesEventMatcher::none();
        }
        $asOf ??= CarbonImmutable::now($shop->timezone);

        return new SalesEventMatcher($this->events->endingFrom($shop, $asOf->subDays((int) config('forecast.history_days'))->toDateString()));
    }

    /** Product picks may be Shopify gids (resource picker): stored as local ids. */
    private function attributes(Shop $shop, array $data): array
    {
        $appliesTo = $data['applies_to'] ?? SalesEvent::ALL;
        $ids = null;
        if ($appliesTo === SalesEvent::PRODUCTS) {
            $refs = $data['variant_ids'] ?? [];
            $gids = array_filter($refs, 'is_string');
            $ids = array_values(array_unique(array_merge(
                array_map('intval', array_filter($refs, 'is_int')),
                $this->variants->findByShopifyIds($shop, array_map(fn ($g) => Gid::id($g), $gids))->pluck('id')->all(),
            )));
            // Only this shop's products.
            $ids = $this->variants->findMany($shop, $ids)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        }

        return [
            'name' => $data['name'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'multiplier' => $data['multiplier'],
            'applies_to' => $appliesTo,
            'supplier_id' => $appliesTo === SalesEvent::SUPPLIER ? $data['supplier_id'] : null,
            'variant_ids' => $ids,
        ];
    }
}
