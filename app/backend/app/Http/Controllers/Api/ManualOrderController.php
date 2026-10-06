<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManualOrderRequest;
use App\Http\Requests\ManualOrderUpdateRequest;
use App\Http\Resources\ManualOrderResource;
use App\Services\App\ManualOrderService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Orders placed outside Shopify, counted as stock on the way. */
class ManualOrderController extends Controller
{
    public function __construct(private readonly ManualOrderService $orders) {}

    public function index(ShopContext $context): JsonResponse
    {
        $list = $this->orders->list($context->shop());
        request()->attributes->set('today', $list['today']);

        return response()->json(['data' => [
            'today' => $list['today'],
            'open' => ManualOrderResource::collection($list['open'])->resolve(),
            'closed' => ManualOrderResource::collection($list['closed'])->resolve(),
        ]]);
    }

    public function store(ManualOrderRequest $request, ShopContext $context): JsonResponse
    {
        $count = $this->orders->record($context->shop(), $request->validated('items'), $request->validated('expected_on'), $request->validated('reference'));

        return response()->json(['data' => ['recorded' => $count]], 201);
    }

    public function update(ManualOrderUpdateRequest $request, ShopContext $context, int $order): ManualOrderResource
    {
        $shop = $context->shop();
        $model = $this->orders->find($shop, $order) ?? throw ApiException::notFound('order');
        request()->attributes->set('today', $this->orders->today($shop));

        return new ManualOrderResource($this->orders->update($shop, $model, $request->validated())->load(['variant', 'supplier:id,name']));
    }
}
