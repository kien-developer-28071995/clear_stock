<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\ForecastAccuracyService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Past forecasts next to what really sold (Insights). */
class ForecastAccuracyController extends Controller
{
    public function __invoke(ForecastAccuracyService $accuracy, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $accuracy->report($context->shop())]);
    }
}
