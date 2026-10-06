<?php

namespace App\Http\Controllers;

use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Single endpoint for all webhook topics (routed by X-Shopify-Topic).
 * Only validates, de-duplicates and enqueues: Shopify expects 200 within 5 seconds.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function __invoke(Request $request): Response
    {
        $this->webhooks->receive(
            topic: (string) $request->header('X-Shopify-Topic', ''),
            shopDomain: (string) $request->header('X-Shopify-Shop-Domain', ''),
            webhookId: $request->header('X-Shopify-Webhook-Id'),
            payload: $request->json()->all(),
        );

        return response()->noContent(200);
    }
}
