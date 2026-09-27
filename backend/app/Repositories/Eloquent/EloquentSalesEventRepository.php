<?php

namespace App\Repositories\Eloquent;

use App\Models\SalesEvent;
use App\Models\Shop;
use App\Repositories\Contracts\SalesEventRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentSalesEventRepository implements SalesEventRepositoryInterface
{
    public function allForShop(Shop $shop): Collection
    {
        return SalesEvent::query()->forShop($shop)->with('supplier:id,name')->orderByDesc('starts_on')->orderByDesc('id')->get();
    }

    public function endingFrom(Shop $shop, string $fromDate): Collection
    {
        return SalesEvent::query()->forShop($shop)->where('ends_on', '>=', $fromDate)->orderBy('starts_on')->orderBy('id')->get();
    }

    public function find(Shop $shop, int $id): ?SalesEvent
    {
        return SalesEvent::query()->forShop($shop)->find($id);
    }

    public function create(Shop $shop, array $attributes): SalesEvent
    {
        return SalesEvent::create($attributes + ['shop_id' => $shop->id])->load('supplier:id,name');
    }

    public function update(SalesEvent $event, array $attributes): SalesEvent
    {
        $event->fill($attributes)->save();

        return $event->load('supplier:id,name');
    }

    public function delete(SalesEvent $event): void
    {
        $event->delete();
    }
}
