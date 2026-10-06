<?php

namespace App\Services\Sync;

use App\Enums\Feature;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Support\Entitlements;

/**
 * Per-location sales and forecasts are only produced when they are used: Growth plan,
 * 2+ active locations, and the fulfillment order scopes granted (older installs
 * re-consent to the new scopes the next time they open the app).
 */
class LocationSupport
{
    public const REQUIRED_SCOPE = 'read_merchant_managed_fulfillment_orders';

    public function __construct(private readonly CatalogRepositoryInterface $catalog) {}

    public function enabled(Shop $shop): bool
    {
        return Entitlements::for($shop)->has(Feature::Locations)
            && in_array(self::REQUIRED_SCOPE, explode(',', (string) $shop->scopes), true)
            && count($this->catalog->activeLocationIds($shop)) >= 2;
    }
}
