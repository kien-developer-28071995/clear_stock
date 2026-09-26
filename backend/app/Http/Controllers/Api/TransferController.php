<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferRequest;
use App\Services\Transfer\TransferService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Growth: stock transfer suggestions between locations, created as draft transfers in Shopify. */
class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function index(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->transfers->suggestions($context->shop())]);
    }

    public function store(TransferRequest $request, ShopContext $context): JsonResponse
    {
        $transfer = $this->transfers->create(
            $context->shop(),
            (int) $request->validated('origin_location_id'),
            (int) $request->validated('destination_location_id'),
            $request->validated('items'),
            $request->validated('idempotency_key'),
        );

        return response()->json(['data' => [
            'id' => $transfer->id,
            'shopify_transfer_id' => $transfer->shopify_transfer_id,
            'name' => $transfer->name,
            'total_units' => $transfer->total_units,
        ]], 201);
    }
}
