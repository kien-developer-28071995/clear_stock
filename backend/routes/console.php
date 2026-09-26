<?php

use Illuminate\Support\Facades\Schedule;

// Nightly sync per shop timezone (the command picks shops whose local hour matches).
Schedule::command('sync:nightly')->hourlyAt(5)->withoutOverlapping()->onOneServer();
Schedule::command('forecast:nightly')->hourlyAt(15)->withoutOverlapping()->onOneServer();
Schedule::command('alerts:send')->hourlyAt(30)->withoutOverlapping()->onOneServer();
Schedule::command('sync:maintenance')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
