<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\CostService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Unit costs entered in the app (missing or not the real cost in Shopify). */
class CostController extends Controller
{
    public function __construct(private readonly CostService $costs) {}

    public function index(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['missing' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->costs->list($context->shop(), (bool) ($data['missing'] ?? false), $data['search'] ?? null)]);
    }

    public function update(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.cost' => ['present', 'nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);

        return response()->json(['data' => ['updated' => $this->costs->update($context->shop(), $data['items'])]]);
    }

    public function import(Request $request, ShopContext $context): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel']]);

        return response()->json(['data' => $this->costs->import($context->shop(), $request->file('file'))]);
    }
}
