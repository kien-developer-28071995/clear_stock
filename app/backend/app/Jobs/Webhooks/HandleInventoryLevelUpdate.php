<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\RealtimeAlertService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Live stock change (real-time alerts). Cheap: a few queries, no Shopify call. */
class HandleInventoryLevelUpdate implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    /** @param array{inventory_item_id: ?int, location_id: ?int, available: ?int, updated_at: ?string} $payload */
    public function __construct(public readonly string $shopDomain, public readonly array $payload) {}

    public function handle(RealtimeAlertService $alerts, ShopRepositoryInterface $shops): void
    {
        $shop = $shops->findByDomain($this->shopDomain);
        if ($shop !== null) {
            $alerts->handleInventoryUpdate($shop, $this->payload);
        }
    }
}
