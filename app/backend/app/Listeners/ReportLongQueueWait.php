<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;

/** A queue waiting longer than its threshold (config/horizon.php "waits") is logged as an error, so it reaches Slack. */
class ReportLongQueueWait
{
    public function handle(LongWaitDetected $event): void
    {
        Log::error("Queue {$event->connection}:{$event->queue} is backed up", [
            'queue' => "{$event->connection}:{$event->queue}",
            'error' => "Oldest job waited {$event->seconds}s",
        ]);
    }
}
