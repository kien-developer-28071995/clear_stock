<?php

use Illuminate\Support\Facades\Schedule;

// Nightly sync per shop timezone (the command picks shops whose local hour matches).
Schedule::command('sync:nightly')->hourlyAt(5)->withoutOverlapping()->onOneServer();
Schedule::command('sync:maintenance')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
