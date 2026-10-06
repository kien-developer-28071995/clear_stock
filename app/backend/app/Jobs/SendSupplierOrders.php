<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\SupplierEmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Automatic purchase order emails for one shop's opted-in suppliers (the service decides what is due). */
class SendSupplierOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(SupplierEmailService $emails, ShopRepositoryInterface $shops): void
    {
        if ($shop = $shops->findById($this->shopId)) {
            $emails->sendDue($shop);
        }
    }
}
