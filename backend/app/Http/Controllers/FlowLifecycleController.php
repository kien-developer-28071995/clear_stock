<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\FlowRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\ShopDomain;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Shopify Flow lifecycle callback (extensions/flow-lifecycle): a workflow using one of our
 * triggers was turned on or off. HMAC-verified like webhooks. Unknown shops are acknowledged.
 * https://shopify.dev/docs/apps/build/flow/track-lifecycle-events
 */
class FlowLifecycleController extends Controller
{
    public function __invoke(Request $request, ShopRepositoryInterface $shops, FlowRepositoryInterface $flow): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'flow_trigger_definition_id' => ['required', 'string', 'max:255'],
            'has_enabled_flow' => ['required', 'boolean'],
            'shopify_domain' => ['required', 'string', 'max:255'],
            'timestamp' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            return response()->json(['code' => 'invalid_payload'], 422);
        }
        $data = $validator->validated();

        $domain = ShopDomain::normalize($data['shopify_domain']);
        if ($domain !== null && ($shop = $shops->findByDomain($domain)) !== null) {
            $flow->recordLifecycle($shop, $data['flow_trigger_definition_id'], (bool) $data['has_enabled_flow'], CarbonImmutable::parse($data['timestamp']));
        }

        return response()->json(['ok' => true]);
    }
}
