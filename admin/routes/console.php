<?php

use Illuminate\Support\Facades\Schedule;

// Installs, uninstalls and plan changes: the app deletes a shop 48h after an uninstall, so the
// ledger must have seen it before that. Every 15 minutes leaves a wide margin.
Schedule::command('report:sync')->everyFifteenMinutes()->withoutOverlapping(10);
