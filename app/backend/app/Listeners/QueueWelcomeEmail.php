<?php

namespace App\Listeners;

use App\Events\ShopInstalled;
use App\Jobs\SendWelcomeEmail;
use App\Support\Features;

/** One welcome email on a shop's first install: never on a reinstall, the merchant already knows the app. */
class QueueWelcomeEmail
{
    public function handle(ShopInstalled $event): void
    {
        if (! $event->reinstall && Features::on('welcome_email')) {
            SendWelcomeEmail::dispatch($event->shop->id);
        }
    }
}
