<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\DataHealthService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Product data problems that make forecasts or money figures wrong. */
class DataHealthController extends Controller
{
    public function __invoke(ShopContext $context, DataHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->check($context->shop())]);
    }
}
