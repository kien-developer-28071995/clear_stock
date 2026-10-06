<?php

namespace App\Jobs\Webhooks;

use App\Exceptions\ShopifyReauthorizeException;
use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\BillingService;
use App\Support\Monitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Plan approved, cancelled, frozen, renewed...: re-read the active subscription from Shopify. */
class HandleAppSubscriptionUpdate implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public function __construct(public readonly string $shopDomain) {}

    public function handle(BillingService $billing, ShopRepositoryInterface $shops): void
    {
        $shop = $shops->findByDomain($this->shopDomain);
        if ($shop === null || ! $shop->isInstalled()) {
            return;
        }

        try {
            $billing->refresh($shop);
        } catch (ShopifyReauthorizeException $e) {
            // Token gone (e.g. uninstalled meanwhile): nothing to update.
            Monitor::expected($e, 'subscription webhook', ['shop' => $shop->domain]);
        }
    }
}
