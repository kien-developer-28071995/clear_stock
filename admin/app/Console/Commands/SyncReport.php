<?php

namespace App\Console\Commands;

use App\Reports\LedgerSync;
use Illuminate\Console\Command;

/** Reads the app's shops into the ledger and stores today's totals. Scheduled every 15 minutes. */
class SyncReport extends Command
{
    protected $signature = 'report:sync';

    protected $description = 'Record installs, uninstalls and plan changes since the last run';

    public function handle(LedgerSync $sync): int
    {
        $stats = $sync->run();
        $this->info(collect($stats)->map(fn ($n, $key) => "{$key}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
