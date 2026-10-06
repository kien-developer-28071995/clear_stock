<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies X-Shopify-Hmac-Sha256 (base64 HMAC-SHA256 of the raw body, keyed with
 * the app secret). Invalid deliveries get 401, as required for compliance webhooks.
 * https://shopify.dev/docs/apps/build/webhooks/verify-deliveries
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.api_secret');
        $header = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if ($secret === '' || $header === '' || ! hash_equals($expected, $header)) {
            return response()->json(['code' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
