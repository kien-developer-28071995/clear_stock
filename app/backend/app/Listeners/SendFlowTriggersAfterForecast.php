<?php

namespace App\Listeners;

use App\Events\ForecastsUpdated;
use App\Jobs\SendFlowTriggers;
use App\Services\Flow\FlowTriggerService;

/** New forecasts may reach a reorder date or a stock-out threshold: check the Flow triggers. */
class SendFlowTriggersAfterForecast
{
    public function __construct(private readonly FlowTriggerService $flow) {}

    public function handle(ForecastsUpdated $event): void
    {
        if ($this->flow->shouldSend($event->shop)) {
            // A short delay lets a burst of recomputes (settings edits) settle into one run.
            SendFlowTriggers::dispatch($event->shop->id)->delay(now()->addMinute());
        }
    }
}
