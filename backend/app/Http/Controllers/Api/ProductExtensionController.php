<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSettingsRequest;
use App\Services\App\ProductExtensionService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Endpoints for the Shopify admin extensions (called cross-origin with a Shopify ID token). */
class ProductExtensionController extends Controller
{
    public function __construct(private readonly ProductExtensionService $products) {}

    public function show(int $product, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->products->productForecast($context->shop(), $product)]);
    }

    public function updateSettings(ProductSettingsRequest $request, ShopContext $context): JsonResponse
    {
        $settings = $request->settings();
        if ($settings === []) {
            return response()->json(['data' => ['updated' => 0]]);
        }

        return response()->json(['data' => ['updated' => $this->products->applySettings($context->shop(), $request->validated('product_ids'), $settings)]]);
    }
}
