<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\SavedViewService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saved product list views. */
class SavedViewController extends Controller
{
    public function __construct(private readonly SavedViewService $views) {}

    public function index(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->views->list($context->shop())]);
    }

    public function store(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'filters' => ['present', 'array']]);

        return response()->json(['data' => $this->views->save($context->shop(), trim($data['name']), $data['filters'])], 201);
    }

    public function destroy(ShopContext $context, int $view): JsonResponse
    {
        return response()->json(['data' => $this->views->delete($context->shop(), $view)]);
    }
}
