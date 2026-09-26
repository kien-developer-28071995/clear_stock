<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\VendorSupplierService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Create suppliers from the Vendor field of Shopify products. */
class VendorSupplierController extends Controller
{
    public function __construct(private readonly VendorSupplierService $vendors) {}

    public function show(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->vendors->preview($context->shop())]);
    }

    public function store(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate([
            'vendors' => ['required', 'array', 'min:1', 'max:1000'],
            'vendors.*' => ['required', 'string', 'max:255'],
            'replace_existing' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->vendors->apply($context->shop(), $data['vendors'], (bool) ($data['replace_existing'] ?? false))]);
    }
}
