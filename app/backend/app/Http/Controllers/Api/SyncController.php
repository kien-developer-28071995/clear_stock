<?php

namespace App\Http\Controllers\Api;

use App\Enums\SyncType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncStatusResource;
use App\Services\Sync\SyncService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function __construct(private readonly SyncService $sync) {}

    public function show(ShopContext $context): JsonResponse
    {
        $shop = $context->shop();

        return (new SyncStatusResource(['shop' => $shop, 'run' => $this->sync->latest($shop)]))->response();
    }

    /** Manual "Sync now". Returns the running sync if there is one. */
    public function store(ShopContext $context): JsonResponse
    {
        $shop = $context->shop();
        $latest = $this->sync->latest($shop);

        $cooldown = (int) config('sync.manual_cooldown_minutes');
        if ($latest && ! $latest->isRunning() && $latest->started_at->gt(now()->subMinutes($cooldown))) {
            throw new ApiException('sync_cooldown', 429, ['minutes' => $cooldown]);
        }

        $run = $this->sync->start($shop, SyncType::Manual);

        return (new SyncStatusResource(['shop' => $shop->refresh(), 'run' => $run]))->response()->setStatusCode(202);
    }
}
