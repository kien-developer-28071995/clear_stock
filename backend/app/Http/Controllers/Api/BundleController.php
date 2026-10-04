<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BundleRequest;
use App\Http\Resources\BundleResource;
use App\Repositories\Contracts\CatalogRepositoryInterface;
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
        private readonly CatalogRepositoryInterface $catalog,
    ) {}

    public function index(ShopContext $context): AnonymousResourceCollection
    {
        // Stock per product, to say how many of each bundle the components on hand make.
        request()->attributes->set('stock', $this->catalog->stockByVariant($context->shop()));

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
        $bundle = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('bundle');
        $this->bundles->delete($shop, $bundle);

        return response()->noContent();
    }
}
