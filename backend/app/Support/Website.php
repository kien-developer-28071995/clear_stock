<?php

namespace App\Support;

/**
 * Links to the marketing website (website/ in the repo, config shopify.website_url). English
 * pages are at the root, other languages under their code: /support, /vi/support.
 */
final class Website
{
    /** url('support') = https://site/support, url('support', 'vi') = https://site/vi/support, url() = https://site/ */
    public static function url(string $page = '', ?string $locale = null): string
    {
        $base = (string) config('shopify.website_url');
        $prefix = $locale !== null && $locale !== 'en' && in_array($locale, Locales::supported(), true) ? "/{$locale}" : '';
        $path = $prefix.($page === '' ? '' : "/{$page}");

        return $base.($path === '' ? '/' : $path);
    }
}
