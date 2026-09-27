<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchasePlanRequest;
use App\Services\App\PurchasePlanService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** What to order and spend, week by week, at the current sales rates. Nothing is saved. */
class PurchasePlanController extends Controller
{
    public function __construct(private readonly PurchasePlanService $plans) {}

    public function show(PurchasePlanRequest $request, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->plans->build($context->shop(), (int) $request->validated('weeks', 12), $this->filters($request))]);
    }

    /** Every planned order as a CSV (one line per order). */
    public function export(PurchasePlanRequest $request, ShopContext $context): StreamedResponse
    {
        $csv = $this->plans->export($context->shop(), (int) $request->validated('weeks', 12), $this->filters($request));

        return response()->streamDownload(function () use ($csv) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            foreach ($csv['rows'] as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $csv['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{supplier_id?: int, vendor?: string} */
    private function filters(PurchasePlanRequest $request): array
    {
        return array_filter([
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->validated('supplier_id') : null,
            'vendor' => $request->validated('vendor'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
