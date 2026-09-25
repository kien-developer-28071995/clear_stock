<?php

namespace App\Events;

use App\Models\Shop;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShopUninstalled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Shop $shop) {}
}
