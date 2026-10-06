<?php

namespace App\Console\Commands;

use App\Exceptions\SyncFailedException;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;

class SyncMaintenance extends Command
{
    protected $signature = 'sync:maintenance';

    protected $description = 'Fail stuck sync runs and prune old ones';

    public function handle(SyncRunRepositoryInterface $runs, SyncService $sync): int
    {
        $stuck = $runs->runningStartedBefore(now()->subHours(config('sync.stuck_after_hours')));
        foreach ($stuck as $run) {
            $sync->fail($run->id, new SyncFailedException('timeout'));
        }

        $pruned = $runs->pruneFinishedBefore(now()->subDays(config('sync.keep_runs_days')));
        $this->info("Failed {$stuck->count()} stuck run(s), pruned {$pruned} old run(s).");

        return self::SUCCESS;
    }
}
