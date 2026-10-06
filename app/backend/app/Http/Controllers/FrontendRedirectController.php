<?php

namespace App\Http\Controllers;

use App\Support\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * This backend serves no page of the app: the frontend is a site of its own (app/frontend/, config
 * shopify.frontend_url) that calls the API here. A browser that still lands on the backend
 * (an application_url from before the split, an old bookmark, /auth/callback) is sent on.
 */
class FrontendRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $frontend = config('shopify.frontend_url');
        // No frontend to send to, or nobody Shopify sent: the website introduces the app.
        if ($frontend === null || ! $request->filled('shop')) {
            return redirect()->away(Website::url());
        }

        $path = $request->is('auth/callback') ? '' : ltrim($request->path(), '/');
        // As Shopify sent it (not re-sorted): the frontend's App Bridge reads it.
        $query = (string) $request->server('QUERY_STRING');

        return redirect()->away($frontend.'/'.$path.($query !== '' ? "?{$query}" : ''));
    }
}
