<?php

use App\Models\ChangeLog;
use App\Models\EmailLog;
use App\Models\FeatureEvent;
use App\Models\WebVital;
use Illuminate\Support\Facades\Schedule;

// withoutOverlapping() locks expire after N minutes (default 24h): a scheduler killed mid-run (every
// deploy restarts it) must not leave a lock that skips a task for a day. Each expiry is below the interval.

// Nightly sync per shop timezone (the command picks shops whose local hour matches).
Schedule::command('sync:nightly')->hourlyAt(5)->withoutOverlapping(50)->onOneServer();
Schedule::command('forecast:nightly')->hourlyAt(15)->withoutOverlapping(50)->onOneServer();
Schedule::command('alerts:send')->hourlyAt(30)->withoutOverlapping(50)->onOneServer();
Schedule::command('alerts:realtime-sync')->dailyAt('02:40')->withoutOverlapping(120)->onOneServer();
Schedule::command('suppliers:send-orders')->hourlyAt(40)->withoutOverlapping(50)->onOneServer();
Schedule::command('sync:maintenance')->everyThirtyMinutes()->withoutOverlapping(25)->onOneServer();
// Missed app_subscriptions/update webhooks: the plan is re-read from Shopify once a day.
Schedule::command('billing:reconcile')->dailyAt('04:20')->withoutOverlapping(120)->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('model:prune', ['--model' => [EmailLog::class, WebVital::class, FeatureEvent::class, ChangeLog::class]])->dailyAt('03:10')->onOneServer();
