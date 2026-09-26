<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Services\App\SupplierService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(ShopContext $context): AnonymousResourceCollection
    {
        return SupplierResource::collection($this->suppliers->list($context->shop()));
    }

    public function store(SupplierRequest $request, ShopContext $context): JsonResponse
    {
        return (new SupplierResource($this->suppliers->create($context->shop(), $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SupplierRequest $request, ShopContext $context, int $supplier): SupplierResource
    {
        $shop = $context->shop();
        $model = $this->suppliers->find($shop, $supplier) ?? abort(404, 'Supplier not found.');

        return new SupplierResource($this->suppliers->update($shop, $model, $request->validated()));
    }

    public function destroy(ShopContext $context, int $supplier): Response
    {
        $shop = $context->shop();
        $model = $this->suppliers->find($shop, $supplier) ?? abort(404, 'Supplier not found.');
        $this->suppliers->delete($shop, $model);

        return response()->noContent();
    }
}
