<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\PurchaseOrderService;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function export(Request $request, PurchaseOrderService $orders, ShopContext $context): StreamedResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'variant_ids' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'], // "1,2,3"
        ]);
        $po = $orders->build(
            $context->shop(),
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            isset($data['location_id']) ? (int) $data['location_id'] : null,
            isset($data['variant_ids']) ? array_map('intval', explode(',', $data['variant_ids'])) : null,
        );

        return response()->streamDownload(function () use ($po) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            foreach ($po['rows'] as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $po['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
