<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Public privacy policy and support pages (App Store listing URLs). The content is the
 * React bundle frontend/src/public.tsx (translated like the app); this only serves the shell.
 */
class PublicPageController extends Controller
{
    /** Bump when the privacy policy text changes (frontend/src/i18n/locales, legal.privacy). */
    private const PRIVACY_UPDATED = '2026-09-26';

    public function privacy(): View
    {
        return $this->page('Privacy policy');
    }

    public function support(): View
    {
        return $this->page('Support');
    }

    private function page(string $title): View
    {
        return view('public-app', [
            'title' => $title,
            'appName' => config('shopify.app_name'),
            'supportEmail' => config('shopify.support_email'),
            'privacyUpdated' => self::PRIVACY_UPDATED,
        ]);
    }
}
