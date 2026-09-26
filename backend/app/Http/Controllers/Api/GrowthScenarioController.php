<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrowthScenarioRequest;
use App\Services\App\GrowthScenarioService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** "If sales grow by X%, what do I need to order?" Nothing is saved. */
class GrowthScenarioController extends Controller
{
    public function __construct(private readonly GrowthScenarioService $scenarios) {}

    public function __invoke(GrowthScenarioRequest $request, ShopContext $context): JsonResponse
    {
        $filters = array_filter([
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->validated('supplier_id') : null,
            'vendor' => $request->validated('vendor'),
            'abc' => $request->validated('abc'),
        ], fn ($v) => $v !== null && $v !== '');

        return response()->json(['data' => $this->scenarios->simulate(
            $context->shop(),
            (int) $request->validated('growth'),
            (int) $request->validated('horizon', 30),
            $filters,
        )]);
    }
}
