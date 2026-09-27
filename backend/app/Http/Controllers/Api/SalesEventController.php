<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalesEventRequest;
use App\Http\Resources\SalesEventResource;
use App\Services\App\SalesEventService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Promotions and other known sales changes (upcoming ones feed the orders). */
class SalesEventController extends Controller
{
    public function __construct(private readonly SalesEventService $events) {}

    public function index(ShopContext $context): AnonymousResourceCollection
    {
        return SalesEventResource::collection($this->events->list($context->shop()));
    }

    public function store(SalesEventRequest $request, ShopContext $context): JsonResponse
    {
        return (new SalesEventResource($this->events->create($context->shop(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SalesEventRequest $request, ShopContext $context, int $event): SalesEventResource
    {
        $shop = $context->shop();
        $model = $this->events->find($shop, $event) ?? throw ApiException::notFound('sales_event');

        return new SalesEventResource($this->events->update($shop, $model, $request->validated()));
    }

    public function destroy(ShopContext $context, int $event): Response
    {
        $shop = $context->shop();
        $model = $this->events->find($shop, $event) ?? throw ApiException::notFound('sales_event');
        $this->events->delete($shop, $model);

        return response()->noContent();
    }
}
