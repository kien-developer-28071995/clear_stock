<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\StockHistoryService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Inventory units and value at cost, day by day. */
class StockHistoryController extends Controller
{
    public function __invoke(Request $request, ShopContext $context, StockHistoryService $history): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', Rule::in([30, 90, 180, 365])]]);

        return response()->json(['data' => $history->history($context->shop(), (int) ($data['days'] ?? 90))]);
    }
}
