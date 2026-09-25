<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopResource;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

class ShopController extends Controller
{
    public function show(ShopContext $context): JsonResponse
    {
        // Explicit 200: the shop may have just been created by the install in this request.
        return (new ShopResource($context->shop()))->response()->setStatusCode(200);
    }
}
