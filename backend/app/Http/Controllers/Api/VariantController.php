<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VariantSettingsRequest;
use App\Http\Resources\VariantOptionResource;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\App\CostService;
use App\Services\App\ForecastAdjustmentService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VariantController extends Controller
{
    public function __construct(
        private readonly VariantRepositoryInterface $variants,
        private readonly ForecastAdjustmentService $adjust,
        private readonly CostService $costs,
    ) {}

    /** Search for pickers. */
    public function index(Request $request, ShopContext $context): AnonymousResourceCollection
    {
        $term = (string) $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '';

        return VariantOptionResource::collection($this->variants->search($context->shop(), $term, 20));
    }

    public function updateSettings(VariantSettingsRequest $request, ShopContext $context, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');
        if ($request->costSetting() !== []) {
            $this->costs->update($shop, [['variant_id' => $model->id, 'cost' => $request->costSetting()['cost_override']]]);
            $model->refresh();
        }
        $model = $this->adjust->updateVariantSettings($shop, $model, $request->settings(), $request->referenceSettings());

        return response()->json(['data' => $model->only(['id', 'supplier_id', 'lead_time_override', 'safety_days', 'min_order_qty', 'pack_size', 'min_stock', 'max_stock', 'alerts_muted', 'discontinued', 'reference_variant_id', 'reference_percent', 'cost_override'])]);
    }

    public function bulkUpdateSettings(VariantSettingsRequest $request, ShopContext $context): JsonResponse
    {
        $request->validate(['variant_ids' => ['required']]);
        $updated = $this->adjust->bulkUpdateVariantSettings($context->shop(), $request->validated('variant_ids'), $request->settings());

        return response()->json(['data' => ['updated' => $updated]]);
    }
}
