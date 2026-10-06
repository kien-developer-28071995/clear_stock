<?php

namespace App\Services;

use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyReauthorizeException;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Shopify\AdminApiClient;
use App\Support\Monitor;

class ShopService
{
    private const SHOP_DETAILS_QUERY = <<<'GQL'
        query ShopDetails {
          shop { name currencyCode ianaTimezone }
        }
        GQL;

    public function __construct(
        private readonly ShopRepositoryInterface $shops,
        private readonly AdminApiClient $admin,
    ) {}

    /** Pull name, currency and IANA timezone from Shopify. Failures are logged, never fatal. */
    public function refreshDetails(Shop $shop): Shop
    {
        try {
            $data = $this->admin->query($shop, self::SHOP_DETAILS_QUERY)['shop'] ?? [];
        } catch (ShopifyApiException|ShopifyReauthorizeException $e) {
            Monitor::caught($e, 'loading shop details', ['shop' => $shop->domain]);

            return $shop;
        }

        return $this->shops->update($shop, array_filter([
            'name' => $data['name'] ?? null,
            'currency' => $data['currencyCode'] ?? null,
            'timezone' => $data['ianaTimezone'] ?? null,
        ]));
    }
}
