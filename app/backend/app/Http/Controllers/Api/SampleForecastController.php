<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Forecast\SampleForecasts;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Forecasts of a made-up catalog, shown on Home until the shop has its own. */
class SampleForecastController extends Controller
{
    public function __invoke(SampleForecasts $samples, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $samples->for($context->shop())]);
    }
}
