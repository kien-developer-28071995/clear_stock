<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderImportRequest;
use App\Services\Import\PurchaseOrderImportService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;

/** Import suppliers, product assignments and lead times from purchase order CSVs (e.g. Stocky). */
class ImportController extends Controller
{
    public function __construct(private readonly PurchaseOrderImportService $import) {}

    public function preview(PurchaseOrderImportRequest $request, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->import->preview($context->shop(), $request->file('files'), $request->mapping())]);
    }

    public function apply(PurchaseOrderImportRequest $request, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->import->apply(
            $context->shop(),
            $request->file('files'),
            $request->mapping(),
            $request->leadTimes(),
            $request->boolean('replace_existing'),
        )]);
    }
}
