<?php

namespace App\Http\Controllers;

use App\Support\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The privacy policy and support pages live on the marketing website (website/). These app
 * URLs stay valid (App Store listing, sent emails) and redirect there, keeping ?lang=.
 */
class PublicPageController extends Controller
{
    public function privacy(Request $request): RedirectResponse
    {
        return $this->website('privacy', $request);
    }

    public function support(Request $request): RedirectResponse
    {
        return $this->website('support', $request);
    }

    private function website(string $page, Request $request): RedirectResponse
    {
        $lang = $request->query('lang');

        return redirect()->away(Website::url($page, is_string($lang) ? $lang : null), 301);
    }
}
