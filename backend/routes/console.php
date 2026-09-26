<?php

use App\Models\EmailLog;
use Illuminate\Support\Facades\Schedule;

// Nightly sync per shop timezone (the command picks shops whose local hour matches).
Schedule::command('sync:nightly')->hourlyAt(5)->withoutOverlapping()->onOneServer();
Schedule::command('forecast:nightly')->hourlyAt(15)->withoutOverlapping()->onOneServer();
Schedule::command('alerts:send')->hourlyAt(30)->withoutOverlapping()->onOneServer();
Schedule::command('alerts:realtime-sync')->dailyAt('02:40')->withoutOverlapping()->onOneServer();
Schedule::command('suppliers:send-orders')->hourlyAt(40)->withoutOverlapping()->onOneServer();
Schedule::command('sync:maintenance')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
// Missed app_subscriptions/update webhooks: the plan is re-read from Shopify once a day.
Schedule::command('billing:reconcile')->dailyAt('04:20')->withoutOverlapping()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('model:prune', ['--model' => [EmailLog::class]])->dailyAt('03:10')->onOneServer();
