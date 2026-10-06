<?php

namespace App\Jobs\Sync;

use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyReauthorizeException;
use App\Exceptions\SyncFailedException;
use App\Jobs\Concerns\RetriesWithBackoff;
use App\Services\Sync\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Base for sync jobs: runs on the long-running "sync" queue, retries transient
 * Shopify errors with backoff and marks the run failed when giving up.
 */
abstract class SyncJob implements ShouldQueue
{
    use Queueable, RetriesWithBackoff {
        RetriesWithBackoff::failed as logFailure;
    }

    public function __construct(public readonly int $runId)
    {
        $this->onConnection(config('sync.queue_connection'))->onQueue('sync');
    }

    abstract protected function run(SyncService $sync): void;

    public function handle(SyncService $sync): void
    {
        try {
            $this->run($sync);
        } catch (SyncFailedException|ShopifyReauthorizeException $e) {
            $sync->fail($this->runId, $e); // retrying would not help
        } catch (ShopifyApiException $e) {
            if (! $e->retryable) {
                $sync->fail($this->runId, $e);

                return;
            }
            throw $e; // retried with backoff; failed() runs after the last attempt
        }
    }

    public function failed(Throwable $e): void
    {
        $this->logFailure($e);
        app(SyncService::class)->fail($this->runId, $e);
    }
}
