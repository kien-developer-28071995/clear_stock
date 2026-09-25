<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily sales are aggregated per variant with no link to customers or orders,
 * so there is no customer data to erase. Logged for the audit trail.
 */
class HandleCustomersRedact implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    /** @param array{customer_id: ?int, orders: array<int>, data_request_id: ?int} $ids */
    public function __construct(public readonly string $shopDomain, public readonly array $ids) {}

    public function handle(): void
    {
        Log::info('customers/redact: no customer data stored', ['shop' => $this->shopDomain] + $this->ids);
    }
}
