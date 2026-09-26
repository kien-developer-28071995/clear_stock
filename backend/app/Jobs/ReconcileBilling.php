<?php

namespace App\Jobs;

use App\Exceptions\ShopifyReauthorizeException;
use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\BillingService;
use App\Support\Monitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily: make one shop's plan match its Shopify subscription (see BillingService::reconcile). */
class ReconcileBilling implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(BillingService $billing, ShopRepositoryInterface $shops): void
    {
        $shop = $shops->findById($this->shopId);
        if ($shop === null || ! $shop->isInstalled()) {
            return;
        }

        try {
            $billing->reconcile($shop);
        } catch (ShopifyReauthorizeException $e) {
            // No usable token (e.g. uninstalled and that webhook was missed too): nothing to bill.
            Monitor::expected($e, 'billing reconciliation', ['shop' => $shop->domain]);
        }
    }
}
