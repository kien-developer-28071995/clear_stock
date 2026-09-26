<?php

namespace App\Http\Controllers\Api;

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Http\Controllers\Controller;
use App\Services\App\BillingService;
use App\Services\App\DowngradeImpactService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly DowngradeImpactService $downgrade,
    ) {}

    /** What the shop stops getting on a smaller plan (empty for upgrades), for the confirmation dialog. */
    public function impact(Request $request, ShopContext $context): JsonResponse
    {
        $target = Plan::from($request->validate(['plan' => ['required', Rule::enum(Plan::class)]])['plan']);
        $shop = $context->shop();

        return response()->json(['data' => [
            'downgrade' => DowngradeImpactService::isDowngrade($shop->plan, $target),
            'lost' => DowngradeImpactService::isDowngrade($shop->plan, $target) ? $this->downgrade->impact($shop, $target) : [],
        ]]);
    }

    /** ?refresh=1 re-reads the subscription from Shopify (after the merchant approved a charge). */
    public function show(Request $request, ShopContext $context): JsonResponse
    {
        $shop = $context->shop();
        if ($request->boolean('refresh')) {
            $shop = $this->billing->refresh($shop);
        }

        return response()->json(['data' => $this->billing->state($shop) + ['plans' => $this->billing->catalog()]]);
    }

    public function store(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::enum(Plan::class)],
            'interval' => ['nullable', Rule::enum(PlanInterval::class)],
        ]);

        $result = $this->billing->change(
            $context->shop(),
            Plan::from($data['plan']),
            isset($data['interval']) ? PlanInterval::from($data['interval']) : null,
        );

        return response()->json(['data' => $result]);
    }
}
