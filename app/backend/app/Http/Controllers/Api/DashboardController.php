<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\DashboardService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $dashboard->build($context->shop())]);
    }
}
