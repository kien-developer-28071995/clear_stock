<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Services\App\SettingsService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function show(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->settings->get($context->shop())]);
    }

    public function update(SettingsRequest $request, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->settings->update($context->shop(), $request->validated())]);
    }
}
