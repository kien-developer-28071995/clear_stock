<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\PurchaseOrderService;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function export(Request $request, PurchaseOrderService $orders, ShopContext $context): StreamedResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'variant_ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'], // "1,2,3"
            'format' => ['nullable', Rule::in([PurchaseOrderService::FORMAT_STANDARD, PurchaseOrderService::FORMAT_SHOPIFY])],
        ]);
        $po = $orders->build(
            $context->shop(),
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            isset($data['location_id']) ? (int) $data['location_id'] : null,
            isset($data['variant_ids']) ? array_map('intval', explode(',', $data['variant_ids'])) : null,
            $data['format'] ?? PurchaseOrderService::FORMAT_STANDARD,
        );

        return response()->streamDownload(function () use ($po) {
            $out = fopen('php://output', 'w');
            if ($po['bom']) {
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            }
            foreach ($po['rows'] as $i => $row) {
                if ($i === 0 && $po['plain_header']) {
                    fwrite($out, implode(',', $row)."\n"); // exactly as Shopify's template ("Supplier SKU" unquoted)

                    continue;
                }
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $po['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Products left out (Shopify format: no SKU and no barcode), shown by the app.
            'X-Skipped-Rows' => (string) $po['skipped'],
        ]);
    }
}
