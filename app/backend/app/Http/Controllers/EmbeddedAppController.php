<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidSessionTokenException;
use App\Exceptions\ShopifyApiException;
use App\Services\ShopAuthService;
use App\Support\Monitor;
use App\Support\ShopDomain;
use App\Support\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Serves the single-page embedded app. Shopify opens it as
 * /?shop=...&host=...&embedded=1&id_token=...
 */
class EmbeddedAppController extends Controller
{
    public function __construct(private readonly ShopAuthService $auth) {}

    public function __invoke(Request $request)
    {
        $shop = ShopDomain::normalize($request->query('shop'));

        // Opened outside the admin (e.g. straight after install from an old link): go embedded.
        if ($shop !== null && $request->query('embedded') !== '1') {
            return redirect()->away('https://'.$shop.'/admin/apps/'.config('shopify.api_key').'/'.ltrim($request->path(), '/'));
        }

        // Not opened by Shopify (someone typed the app's address): the website introduces the app.
        if ($shop === null) {
            return redirect()->away(Website::url());
        }

        // Install/refresh tokens immediately on load when Shopify hands us an ID token.
        // If it fails, the SPA's first API call retries with a fresh session token.
        if ($request->filled('id_token')) {
            try {
                $this->auth->authenticate($request->query('id_token'));
            } catch (InvalidSessionTokenException $e) {
                Log::info('Initial token exchange deferred to API call', ['shop' => $shop, 'error' => $e->getMessage()]);
            } catch (ShopifyApiException $e) {
                // The SPA retries, but Shopify failing a token exchange is worth a look.
                Monitor::caught($e, 'initial token exchange', ['shop' => $shop]);
            }
        }

        return response()->view('app', [
            'apiKey' => config('shopify.api_key'),
            'appName' => config('shopify.app_name'),
        ]);
    }
}
