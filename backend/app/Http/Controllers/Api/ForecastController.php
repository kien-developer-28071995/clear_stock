<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Exceptions\ApiException;
use App\Exceptions\PlanRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForecastIndexRequest;
use App\Http\Requests\OverrideRequest;
use App\Http\Resources\ForecastDetailResource;
use App\Http\Resources\ForecastResource;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\App\ForecastAdjustmentService;
use App\Services\App\ForecastQueryService;
use App\Support\Entitlements;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastQueryService $query,
        private readonly ForecastAdjustmentService $adjust,
        private readonly VariantRepositoryInterface $variants,
        private readonly CatalogRepositoryInterface $catalog,
    ) {}

    public function index(ForecastIndexRequest $request, ShopContext $context): AnonymousResourceCollection
    {
        $shop = $context->shop();
        // Resources read the container's base request, not this FormRequest copy.
        request()->attributes->set('today', $this->query->today($shop));

        $filters = $request->safe()->only(['status', 'search', 'sort', 'vendor', 'product_type']);
        if ($request->filled('location_id')) {
            if (! Entitlements::for($shop)->has(Feature::Locations)) {
                throw new PlanRequiredException(Feature::Locations);
            }
            $filters['location_id'] = (int) $request->validated('location_id');
        }

        return ForecastResource::collection($this->query->list($shop, $filters, (int) $request->validated('page', 1)));
    }

    /** Vendors and product types of tracked products, for the list filters. */
    public function facets(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->catalog->facets($context->shop())]);
    }

    /** Active locations (for the Growth location filter). */
    public function locations(ShopContext $context): JsonResponse
    {
        $shop = $context->shop();

        return response()->json(['data' => Entitlements::for($shop)->has(Feature::Locations) ? $this->query->locations($shop) : []]);
    }

    public function show(Request $request, ShopContext $context, int $variant): JsonResponse
    {
        return $this->detail($request, $context->shop(), $variant);
    }

    public function updateOverrides(OverrideRequest $request, ShopContext $context, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');

        $this->adjust->setOverrides($shop, $model, $request->validated());

        return $this->detail($request, $shop, $variant);
    }

    private function detail(Request $request, Shop $shop, int $variantId): JsonResponse
    {
        $forecast = $this->query->detail($shop, $variantId) ?? throw ApiException::notFound('forecast');
        // Resources read the container's base request (not a FormRequest copy).
        $request = request();
        $request->attributes->set('today', $this->query->today($shop));
        $request->attributes->set('shop_defaults', [
            'lead_time_days' => $shop->default_lead_time_days,
            'safety_days' => $shop->default_safety_days,
        ]);
        $entitlements = Entitlements::for($shop);
        $request->attributes->set('explanations', $entitlements->has(Feature::Explanations));
        $request->attributes->set('by_location', $entitlements->has(Feature::Locations)
            ? $this->query->byLocation($shop, $variantId)
            : null);

        return (new ForecastDetailResource($forecast))->response();
    }
}
