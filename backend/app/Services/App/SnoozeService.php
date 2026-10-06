<?php

namespace App\Services\App;

use App\Models\Shop;
use App\Models\Variant;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Carbon\CarbonImmutable;

/**
 * "Not now" for a reorder suggestion (every plan): until the chosen day the product stays out of
 * the reorder list, the home list and alert emails. Its forecast, status and counts do not change,
 * and an order made for it by hand (picked products) still works.
 */
class SnoozeService
{
    public const SWITCH = 'snooze';

    public const MAX_DAYS = 180;

    public function __construct(private readonly ChangeLogService $changes) {}

    /**
     * @param  array<int, int>  $variantIds
     * @param  ?int  $days  null brings the products back now
     * @return array{updated: int, until: ?string}
     */
    public function snooze(Shop $shop, array $variantIds, ?int $days): array
    {
        $until = $days === null ? null : CarbonImmutable::now($shop->timezone)->addDays($days)->toDateString();
        $query = fn () => Variant::query()->forShop($shop)->whereIn('id', $variantIds);
        $before = $query()->get(['id', 'snoozed_until'])->mapWithKeys(fn (Variant $v) => [$v->id => ['snoozed_until' => $v->snoozed_until]])->all();
        $updated = $query()->update(['snoozed_until' => $until]);

        $this->changes->recordMany($shop, $before, ['snoozed_until' => $until], count($before) > 1 ? 'bulk' : 'app');
        // The lists are cached per forecast and catalog version.
        CacheVersion::bump(CacheKeys::forecastVersion($shop->id));
        CacheVersion::bumpCatalog($shop->id);

        return ['updated' => $updated, 'until' => $until];
    }
}
