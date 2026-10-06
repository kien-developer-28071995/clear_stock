<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebVital;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Web vitals reported by App Bridge from the merchant's browser: how fast the app really is in the admin. */
class WebVitalController extends Controller
{
    public function store(Request $request, ShopContext $context): Response
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'max:200'],
            'metrics' => ['required', 'array', 'max:10'],
            'metrics.*.name' => ['required', 'string'],
            'metrics.*.value' => ['required', 'numeric', 'min:0', 'max:600000'],
        ]);
        // Route pattern only: ids become :id, no query string, nothing free-form is stored.
        $page = mb_substr(preg_replace(['/\?.*$/', '#/\d+#', '#[^a-z/:-]#i'], ['', '/:id', ''], $data['page']) ?: '/', 0, 60);
        $now = now();
        $rows = [];
        foreach ($data['metrics'] as $metric) {
            if (in_array($metric['name'], WebVital::METRICS, true)) {
                $rows[] = ['shop_id' => $context->shop()->id, 'metric' => $metric['name'], 'value' => round((float) $metric['value'], 4), 'page' => $page, 'created_at' => $now];
            }
        }
        if ($rows !== []) {
            WebVital::query()->insert($rows);
        }

        return response()->noContent();
    }
}
