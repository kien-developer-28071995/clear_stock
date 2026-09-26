<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnboardingRequest;
use App\Services\App\OnboardingService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function show(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->onboarding->state($context->shop())]);
    }

    public function store(OnboardingRequest $request, ShopContext $context): JsonResponse
    {
        $shop = $this->onboarding->complete($context->shop(), (int) $request->validated('lead_time_days'), $request->validated('alert_email'));

        return response()->json(['data' => $this->onboarding->state($shop)]);
    }
}
