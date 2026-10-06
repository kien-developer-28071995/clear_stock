<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\RealtimeAlertService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the batched real-time email of a shop. Dispatched with a delay by the first stock
 * transition; later transitions in the window find the job already queued (unique) and ride along.
 * The lock is released when processing starts, so a transition during the send queues the next batch.
 */
class SendRealtimeAlerts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    /** Longer than the batch delay, so a waiting job keeps its lock. */
    public function uniqueFor(): int
    {
        return (int) config('alerts.realtime.batch_minutes') * 60 + 900;
    }

    public function handle(RealtimeAlertService $alerts, ShopRepositoryInterface $shops): void
    {
        if ($shop = $shops->findById($this->shopId)) {
            $alerts->flush($shop);
        }
    }
}
