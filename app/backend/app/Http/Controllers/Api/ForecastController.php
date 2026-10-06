<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForecastIndexRequest;
use App\Http\Requests\OverrideRequest;
use App\Http\Resources\ForecastDetailResource;
use App\Http\Resources\ForecastListResource;
use App\Models\Shop;
use App\Repositories\Contracts\CatalogRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\App\AlternateSupplierService;
use App\Services\App\ChangeLogService;
use App\Services\App\ForecastAccuracyService;
use App\Services\App\ForecastAdjustmentService;
use App\Services\App\ForecastQueryService;
use App\Services\App\SnoozeService;
use App\Support\Csv;
use App\Support\Entitlements;
use App\Support\Features;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastQueryService $query,
        private readonly ForecastAdjustmentService $adjust,
        private readonly VariantRepositoryInterface $variants,
        private readonly CatalogRepositoryInterface $catalog,
        private readonly ForecastAccuracyService $accuracy,
        private readonly AlternateSupplierService $alternates,
    ) {}

    public function index(ForecastIndexRequest $request, ShopContext $context): JsonResponse
    {
        $shop = $context->shop();
        // Resources read the container's base request, not this FormRequest copy.
        request()->attributes->set('today', $this->query->today($shop));

        $filters = $request->safe()->only(['status', 'search', 'sort', 'vendor', 'product_type', 'abc', 'trend']);
        if (! Features::enabled(Feature::Abc)) {
            unset($filters['abc']); // ABC switched off app-wide: ignore a stale filter / sort in a saved URL
            if (($filters['sort'] ?? null) === 'revenue') {
                unset($filters['sort']);
            }
        }
        if ($request->filled('location_id')) {
            Entitlements::for($shop)->require(Feature::Locations);
            $filters['location_id'] = (int) $request->validated('location_id');
        }

        $page = $this->query->list($shop, $filters, (int) $request->validated('page', 1));

        // Slim envelope: the app only pages by number (no per-page URLs / links).
        return response()->json([
            'data' => ForecastListResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** The product list with the current filters as a CSV file (every plan: it is the merchant's own data). */
    public function export(ForecastIndexRequest $request, ShopContext $context): StreamedResponse
    {
        $shop = $context->shop();
        $filters = $request->safe()->only(['status', 'search', 'sort', 'vendor', 'product_type', 'abc', 'trend']);
        if ($request->filled('location_id')) {
            Entitlements::for($shop)->require(Feature::Locations);
            $filters['location_id'] = (int) $request->validated('location_id');
        }
        $rows = $this->query->exportRows($shop, $filters);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            foreach ($rows as $row) {
                fputcsv($out, Csv::safe($row), escape: '');
            }
            fclose($out);
        }, 'products-'.$this->query->today($shop).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    /** What was changed on this product (settings, forecast adjustments), newest first. */
    public function changes(ShopContext $context, ChangeLogService $changes, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');

        return response()->json(['data' => $changes->list($shop, $variant)]);
    }

    /** "Not now": keep products out of the reorder list and alert emails for some days (null = bring back). */
    public function snooze(Request $request, ShopContext $context, SnoozeService $snooze): JsonResponse
    {
        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:500'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
            'days' => ['present', 'nullable', 'integer', 'min:1', 'max:'.SnoozeService::MAX_DAYS],
        ]);

        return response()->json(['data' => $snooze->snooze($context->shop(), $data['variant_ids'], $data['days'])]);
    }

    public function updateOverrides(OverrideRequest $request, ShopContext $context, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');

        $this->adjust->setOverrides($shop, $model, $request->validated());

        return $this->detail($request, $shop, $variant);
    }

    public function updateLocationMinimums(Request $request, ShopContext $context, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');
        $data = $request->validate([
            'minimums' => ['required', 'array', 'max:200'],
            'minimums.*.location_id' => ['required', 'integer'],
            'minimums.*.min_stock' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $this->adjust->setLocationMinimums($shop, $model, $data['minimums']);

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
            'forecast_profile' => $shop->forecast_profile,
        ]);
        $entitlements = Entitlements::for($shop);
        $request->attributes->set('explanations', $entitlements->has(Feature::Explanations));
        $request->attributes->set('accuracy', $this->accuracy->forVariant($shop, $variantId));
        $request->attributes->set('alternate_suppliers', $this->alternates->list($shop, $variantId));
        $request->attributes->set('previous', $this->accuracy->previousWeek($shop, $variantId));
        $request->attributes->set('projection', $this->query->projection($shop, $forecast));
        $request->attributes->set('by_location', $entitlements->has(Feature::Locations)
            ? $this->query->byLocation($shop, $variantId)
            : null);

        return (new ForecastDetailResource($forecast))->response();
    }
}
