<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Standard retry policy: 5 attempts with growing backoff, failures logged for monitoring.
 * Jobs set their own $timeout (Horizon's supervisor default applies otherwise).
 */
trait RetriesWithBackoff
{
    public int $tries = 5;

    /** @return array<int, int> seconds before each retry */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function failed(Throwable $e): void
    {
        Log::error('Job failed permanently', [
            'job' => static::class,
            'shop' => $this->shopDomain ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
