<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Flow\FlowTriggerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Shopify Flow triggers of one shop, after its forecasts changed. */
class SendFlowTriggers implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public int $timeout = 600;

    /** Several forecast runs in a row (sync + settings edits) send once. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(FlowTriggerService $flow, ShopRepositoryInterface $shops): void
    {
        if ($shop = $shops->findById($this->shopId)) {
            $flow->send($shop);
        }
    }
}
