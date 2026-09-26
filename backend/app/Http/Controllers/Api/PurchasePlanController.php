<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchasePlanRequest;
use App\Services\App\PurchasePlanService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** What to order and spend, week by week, at the current sales rates. Nothing is saved. */
class PurchasePlanController extends Controller
{
    public function __construct(private readonly PurchasePlanService $plans) {}

    public function __invoke(PurchasePlanRequest $request, ShopContext $context): JsonResponse
    {
        $filters = array_filter([
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->validated('supplier_id') : null,
            'vendor' => $request->validated('vendor'),
        ], fn ($v) => $v !== null && $v !== '');

        return response()->json(['data' => $this->plans->build($context->shop(), (int) $request->validated('weeks', 12), $filters)]);
    }
}
