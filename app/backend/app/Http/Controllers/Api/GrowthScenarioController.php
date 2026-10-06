<?php

namespace App\Http\Controllers\Api;

use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrowthScenarioRequest;
use App\Services\App\GrowthScenarioService;
use App\Support\Features;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "If sales grow by X%, what do I need to order?" Nothing is saved. */
class GrowthScenarioController extends Controller
{
    public function __construct(private readonly GrowthScenarioService $scenarios) {}

    public function show(GrowthScenarioRequest $request, ShopContext $context): JsonResponse
    {
        return response()->json(['data' => $this->scenarios->simulate(
            $context->shop(),
            (int) $request->validated('growth'),
            (int) $request->validated('horizon', 30),
            $this->filters($request),
        )]);
    }

    /** The scenario's orders as a purchase order CSV. */
    public function export(GrowthScenarioRequest $request, ShopContext $context): StreamedResponse
    {
        $csv = $this->scenarios->export($context->shop(), (int) $request->validated('growth'), (int) $request->validated('horizon', 30), $this->filters($request));

        return response()->streamDownload(function () use ($csv) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            foreach ($csv['rows'] as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, $csv['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(GrowthScenarioRequest $request): array
    {
        return array_filter([
            'supplier_id' => $request->filled('supplier_id') ? (int) $request->validated('supplier_id') : null,
            'vendor' => $request->validated('vendor'),
            'abc' => Features::enabled(Feature::Abc) ? $request->validated('abc') : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
