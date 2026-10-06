<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\App\StockInsightService;
use App\Support\Csv;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Clearance list and broken size runs (Insights). */
class StockInsightController extends Controller
{
    public function __construct(private readonly StockInsightService $insights) {}

    public function clearance(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->insights->clearance($context->shop(), 50)]);
    }

    public function clearanceExport(ShopContext $context): StreamedResponse
    {
        $shop = $context->shop();
        $rows = $this->insights->clearanceRows($shop);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, Csv::safe($row), escape: '');
            }
            fclose($out);
        }, 'clearance-'.now($shop->timezone)->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sizeRuns(ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->insights->sizeRuns($context->shop())]);
    }
}
