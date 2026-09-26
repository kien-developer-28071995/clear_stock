<?php

namespace App\Http\Controllers\Api;

use App\Enums\SetupStep;
use App\Http\Controllers\Controller;
use App\Services\App\SetupGuideService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SetupGuideController extends Controller
{
    public function __construct(private readonly SetupGuideService $guide) {}

    public function show(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->guide->state($context->shop())]);
    }

    public function event(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['event' => ['required', Rule::in(SetupGuideService::EVENTS)]]);

        return response()->json(['data' => $this->guide->recordEvent($context->shop(), $data['event'])]);
    }

    public function skip(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['step' => ['required', Rule::enum(SetupStep::class)]]);

        return response()->json(['data' => $this->guide->skip($context->shop(), SetupStep::from($data['step']))]);
    }

    public function dismiss(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['dismissed' => ['required', 'boolean']]);

        return response()->json(['data' => $this->guide->setDismissed($context->shop(), (bool) $data['dismissed'])]);
    }

    public function dismissTip(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['tip' => ['required', Rule::in(SetupGuideService::TIPS)]]);

        return response()->json(['data' => $this->guide->dismissTip($context->shop(), $data['tip'])]);
    }
}
