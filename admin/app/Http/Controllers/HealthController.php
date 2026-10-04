<?php

namespace App\Http\Controllers;

use App\Models\ShopRecord;
use App\Reports\Health;
use Illuminate\View\View;

class HealthController extends Controller
{
    public function __invoke(Health $health): View
    {
        return view('health', $health->build() + ['records' => ShopRecord::query()->pluck('id', 'app_shop_id')]);
    }
}
