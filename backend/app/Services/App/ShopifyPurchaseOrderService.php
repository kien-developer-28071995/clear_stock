<?php

namespace App\Services\App;

use App\Enums\Feature;
use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Shopify\AdminApiClient;
use App\Services\Shopify\PurchaseOrderClient;
use App\Support\Entitlements;
use App\Support\Monitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shopify's purchase orders that are ordered and still open, next to the orders marked in the app
 * (Starter). Read live and cached a few minutes: nothing is stored. Their units are already in
 * Shopify's "incoming" quantity, which the forecast counts; this only shows what they are.
 * Needs the optional scope `read_inventory_purchase_orders`, asked for in the app.
 */
class ShopifyPurchaseOrderService
{
    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly PurchaseOrderClient $client,
        private readonly AdminApiClient $admin,
        private readonly ShopRepositoryInterface $shops,
    ) {}

    /** @return array{scope_granted: bool, scope: string, orders: array<int, array<string, mixed>>, error: ?string} */
    public function list(Shop $shop, bool $recheckScope = false): array
    {
        Entitlements::for($shop)->require(Feature::ShopifyPurchaseOrders);
        $base = ['scope' => PurchaseOrderClient::SCOPE, 'orders' => [], 'error' => null];

        if (! $this->hasScope($shop) && ! ($recheckScope && $this->hasScope($shop = $this->refreshScopes($shop)))) {
            return ['scope_granted' => false] + $base;
        }

        try {
            $orders = Cache::remember("shop:{$shop->id}:shopify-purchase-orders", self::CACHE_SECONDS, fn () => $this->client->ordered($shop));
        } catch (ShopifyApiException $e) {
            Monitor::caught($e, 'reading Shopify purchase orders', ['shop' => $shop->domain]);

            return ['scope_granted' => true, 'error' => 'shopify_error'] + $base;
        }

        // Lines to the shop's products, by inventory item.
        $items = array_values(array_unique(array_filter(array_merge([], ...array_map(fn ($o) => array_column($o['lines'], 'inventory_item_id'), $orders)))));
        $variants = $items === [] ? collect() : DB::table('variants')->where('shop_id', $shop->id)->whereIn('inventory_item_id', $items)
            ->get(['id', 'inventory_item_id', 'product_title', 'title'])->keyBy('inventory_item_id');

        return ['scope_granted' => true, 'orders' => array_map(fn (array $o) => [
            'id' => $o['id'],
            'name' => $o['name'],
            'supplier' => $o['supplier'],
            'ordered_on' => $o['ordered_on'],
            'units' => array_sum(array_column($o['lines'], 'quantity')),
            'cost' => round(array_sum(array_map(fn ($l) => $l['quantity'] * ($l['unit_cost'] ?? 0), $o['lines'])), 2),
            'currency' => $o['currency'],
            'lines' => array_map(function (array $l) use ($variants) {
                $v = $variants[$l['inventory_item_id']] ?? null;

                return [
                    'variant_id' => $v !== null ? (int) $v->id : null,
                    'name' => $v !== null ? ($v->title && $v->title !== 'Default Title' ? "{$v->product_title} - {$v->title}" : $v->product_title) : $l['title'],
                    'supplier_sku' => $l['supplier_sku'],
                    'quantity' => $l['quantity'],
                ];
            }, $o['lines']),
        ], $orders)] + $base;
    }

    private function hasScope(Shop $shop): bool
    {
        return in_array(PurchaseOrderClient::SCOPE, explode(',', (string) $shop->scopes), true);
    }

    /** The merchant just granted the scope in the app: the app/scopes_update webhook may not have arrived yet. */
    private function refreshScopes(Shop $shop): Shop
    {
        try {
            $data = $this->admin->query($shop, '{ currentAppInstallation { accessScopes { handle } } }');
        } catch (ShopifyApiException $e) {
            Monitor::caught($e, 'checking granted scopes', ['shop' => $shop->domain]);

            return $shop;
        }

        return $this->shops->update($shop, ['scopes' => implode(',', array_column($data['currentAppInstallation']['accessScopes'] ?? [], 'handle'))]);
    }
}
