<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierEmailRequest;
use App\Services\App\SupplierEmailService;
use App\Services\App\SupplierService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Email a supplier a purchase order (Growth). */
class SupplierEmailController extends Controller
{
    public function __construct(
        private readonly SupplierService $suppliers,
        private readonly SupplierEmailService $emails,
    ) {}

    /** The draft: supplier email, reply-to and the products due (or ?variant_ids=1,2). */
    public function show(Request $request, ShopContext $context, int $supplier): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->suppliers->find($shop, $supplier) ?? throw ApiException::notFound('supplier');
        $ids = $request->validate(['variant_ids' => ['nullable', 'regex:/^\d+(,\d+)*$/']])['variant_ids'] ?? null;

        return response()->json(['data' => $this->emails->draft($shop, $model, $ids ? array_map('intval', explode(',', $ids)) : null)]);
    }

    public function store(SupplierEmailRequest $request, ShopContext $context, int $supplier): JsonResponse
    {
        $shop = $context->shop();
        $model = $this->suppliers->find($shop, $supplier) ?? throw ApiException::notFound('supplier');
        $email = $this->emails->send($shop, $model, $request->validated('items'), $request->validated('message'), $request->validated('reply_to'));

        return response()->json(['data' => ['id' => $email->id, 'items' => count($email->items), 'total_units' => $email->total_units]], 202);
    }
}
