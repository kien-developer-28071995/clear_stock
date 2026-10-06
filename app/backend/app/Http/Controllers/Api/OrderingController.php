<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Services\App\AlternateSupplierService;
use App\Services\App\ShopifyPurchaseOrderService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A product's other suppliers, and Shopify's own open purchase orders. */
class OrderingController extends Controller
{
    public function __construct(
        private readonly AlternateSupplierService $alternates,
        private readonly ShopifyPurchaseOrderService $purchaseOrders,
        private readonly VariantRepositoryInterface $variants,
    ) {}

    public function suppliers(ShopContext $context, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');

        return response()->json(['data' => $this->alternates->list($shop, $variant)]);
    }

    public function updateSuppliers(Request $request, ShopContext $context, int $variant): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');
        $data = $request->validate([
            'suppliers' => ['present', 'array', 'max:10'],
            'suppliers.*.supplier_id' => ['required', 'integer'],
            'suppliers.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'suppliers.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'suppliers.*.supplier_sku' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->alternates->replace($shop, $model, $data['suppliers'])]);
    }

    public function makeMainSupplier(ShopContext $context, int $variant, int $supplier): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->variants->find($shop, $variant) ?? throw ApiException::notFound('product');
        $model = $this->alternates->makeMain($shop, $model, $supplier);

        return response()->json(['data' => ['supplier_id' => $model->supplier_id, 'alternates' => $this->alternates->list($shop, $variant)]]);
    }

    public function shopifyPurchaseOrders(Request $request, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->purchaseOrders->list($context->shop(), $request->boolean('recheck'))]);
    }
}
