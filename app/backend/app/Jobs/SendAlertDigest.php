<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\AlertService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAlertDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(AlertService $alerts, ShopRepositoryInterface $shops): void
    {
        if ($shop = $shops->findById($this->shopId)) {
            $alerts->sendIfDue($shop);
        }
    }
}
