<?php

namespace App\Services;

use App\Enums\Plan;
use App\Events\ShopUninstalled;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Support\Facades\Log;

/** Uninstall, scope changes and data erasure driven by webhooks. */
class ShopLifecycleService
{
    public function __construct(private readonly ShopRepositoryInterface $shops) {}

    /**
     * Tokens are dropped immediately (they are revoked anyway). Data is kept until
     * shop/redact (48h later) so a quick reinstall keeps history.
     */
    public function markUninstalled(string $shopDomain): void
    {
        $shop = $this->shops->findByDomain($shopDomain);
        if ($shop === null || $shop->uninstalled_at !== null) {
            return; // idempotent
        }

        $plan = $shop->plan;
        $this->shops->update($shop, [
            'uninstalled_at' => now(),
            'access_token' => null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
            // Shopify cancels app subscriptions on uninstall.
            'plan' => Plan::Free,
            'plan_interval' => null,
            'subscription_id' => null,
            'subscription_status' => null,
            'plan_renews_at' => null,
            // Shopify removes the app's shop-specific webhook subscriptions on uninstall.
            'realtime_webhook_id' => null,
        ]);

        ShopUninstalled::dispatch($shop, $plan);
        Log::info('Shop uninstalled', ['shop' => $shopDomain]);
    }

    /** @param array<int, string> $scopes */
    public function updateScopes(string $shopDomain, array $scopes): void
    {
        $shop = $this->shops->findByDomain($shopDomain);
        if ($shop === null) {
            return;
        }

        $this->shops->update($shop, ['scopes' => implode(',', $scopes)]);
    }

    public function redactShop(string $shopDomain): void
    {
        $shop = $this->shops->findByDomain($shopDomain);
        if ($shop === null) {
            Log::info('shop/redact: nothing stored', ['shop' => $shopDomain]);

            return;
        }

        // Shopify only sends shop/redact 48h after uninstall; if the shop has reinstalled since,
        // its data is in active use again.
        if ($shop->isInstalled()) {
            Log::warning('shop/redact ignored: shop is installed again', ['shop' => $shopDomain]);

            return;
        }

        $this->shops->purge($shop);
        Log::info('shop/redact: all shop data deleted', ['shop' => $shopDomain]);
    }
}
