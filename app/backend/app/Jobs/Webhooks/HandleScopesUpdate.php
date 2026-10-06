<?php

namespace App\Jobs\Webhooks;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Services\ShopLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleScopesUpdate implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    /** @param array<int, string> $scopes */
    public function __construct(public readonly string $shopDomain, public readonly array $scopes) {}

    public function handle(ShopLifecycleService $lifecycle): void
    {
        $lifecycle->updateScopes($this->shopDomain, $this->scopes);
    }
}
