<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BundleRequest;
use App\Http\Resources\BundleResource;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\App\BundleService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BundleController extends Controller
{
    public function __construct(
        private readonly BundleService $bundles,
        private readonly VariantRepositoryInterface $variants,
    ) {}

    public function index(ShopContext $context): AnonymousResourceCollection
    {
        return BundleResource::collection($this->bundles->list($context->shop()));
    }

    public function store(BundleRequest $request, ShopContext $context): JsonResponse
    {
        $bundle = $this->bundles->save($context->shop(), $request->validated('bundle'), $request->validated('components'));

        return (new BundleResource($bundle))->response()->setStatusCode(201);
    }

    public function destroy(ShopContext $context, int $variant): Response
    {
        $shop = $context->shop();
        $bundle = $this->variants->find($shop, $variant) ?? abort(404, 'Bundle not found.');
        $this->bundles->delete($shop, $bundle);

        return response()->noContent();
    }
}
