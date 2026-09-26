<?php

namespace App\Http\Resources;

use App\Enums\SyncStage;
use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sync status for the progress bar.
 *
 * @property array{shop: Shop, run: ?SyncRun} $resource
 */
class SyncStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Shop $shop */
        $shop = $this->resource['shop'];
        /** @var ?SyncRun $run */
        $run = $this->resource['run'];

        return [
            'status' => $shop->sync_status->value,     // pending | running | completed | failed
            'last_synced_at' => $shop->last_synced_at?->toIso8601String(),
            'error' => $shop->sync_error,               // {code, params} or null
            'run' => $run ? [
                'id' => $run->id,
                'type' => $run->type->value,
                'stage' => $run->stage->value,
                'progress' => $run->progress,
                'started_at' => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'stats' => $run->stage === SyncStage::Completed ? $this->summary($run->stats ?? []) : null,
            ] : null,
        ];
    }

    private function summary(array $stats): array
    {
        return [
            'variants' => $stats['catalog']['variants'] ?? 0,
            'orders' => $stats['orders']['orders'] ?? 0,
            'out_of_stock_days' => $stats['stock']['out_of_stock_days'] ?? 0,
        ];
    }
}
