<?php

namespace App\Events;

use App\Models\Shop;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired after a shop installs (or reinstalls) the app and has a valid token. */
class ShopInstalled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Shop $shop) {}
}
