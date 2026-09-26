<?php

namespace App\Console\Commands\Dev;

use App\Models\Shop;
use App\Services\Shopify\ShopTokenService;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;

/**
 * DEV ONLY. Prints an App Bridge-style session token for a shop, so the embedded
 * app can run outside the Shopify admin (end-to-end tests in frontend/e2e).
 */
class SessionToken extends Command
{
    protected $signature = 'dev:session-token
        {--shop= : Shop domain (default: first installed shop)}
        {--ttl=3600 : Lifetime in seconds}';

    protected $description = '[DEV] Print a session token for a shop (end-to-end tests)';

    public function handle(ShopTokenService $tokens): int
    {
        if (! app()->environment('local')) {
            $this->error('This command only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $shop = $this->option('shop') ? Shop::firstWhere('domain', $this->option('shop')) : Shop::query()->whereNull('uninstalled_at')->first();
        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        // An expired access token would make the app exchange this (unsigned by Shopify)
        // token with Shopify, which refuses it: refresh the access token first.
        $tokens->accessToken($shop);

        $now = time();
        $this->line(JWT::encode([
            'iss' => "https://{$shop->domain}/admin",
            'dest' => "https://{$shop->domain}",
            'aud' => config('shopify.api_key'),
            'sub' => '1',
            'exp' => $now + (int) $this->option('ttl'),
            'nbf' => $now - 5,
            'iat' => $now,
            'jti' => bin2hex(random_bytes(8)),
            'sid' => 'dev',
        ], config('shopify.api_secret'), 'HS256'));

        return self::SUCCESS;
    }
}
