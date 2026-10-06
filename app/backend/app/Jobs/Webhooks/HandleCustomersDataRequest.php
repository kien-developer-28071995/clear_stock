<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The app stores no customer personal data (only per-variant daily totals),
 * so there is nothing to report. The request is logged for the audit trail.
 */
class HandleCustomersDataRequest implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    /** @param array{customer_id: ?int, orders: array<int>, data_request_id: ?int} $ids */
    public function __construct(public readonly string $shopDomain, public readonly array $ids) {}

    public function handle(): void
    {
        Log::info('customers/data_request: no customer data stored', ['shop' => $this->shopDomain] + $this->ids);
    }
}
