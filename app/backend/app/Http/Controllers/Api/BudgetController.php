<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\BudgetService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Monthly purchasing budget: what to reorder first (Starter). */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budget) {}

    public function show(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->budget->build($context->shop())]);
    }

    public function update(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['budget' => ['present', 'nullable', 'numeric', 'min:0', 'max:100000000']]);

        return response()->json(['data' => $this->budget->setBudget($context->shop(), $data['budget'] === null ? null : (float) $data['budget'])]);
    }
}
