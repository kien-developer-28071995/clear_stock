<?php

use App\Models\DailySale;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

const TEST_API_KEY = 'test-api-key';
const TEST_API_SECRET = 'shpss_test_secret_0123456789abcdef0123';

/** Mint an App Bridge-style session token (ID token) for tests. */
function sessionToken(string $shop = 'demo.myshopify.com', array $overrides = [], string $secret = TEST_API_SECRET): string
{
    $now = time();

    return JWT::encode(array_merge([
        'iss' => "https://{$shop}/admin",
        'dest' => "https://{$shop}",
        'aud' => TEST_API_KEY,
        'sub' => '42',
        'exp' => $now + 60,
        'nbf' => $now - 5,
        'iat' => $now - 5,
        'jti' => bin2hex(random_bytes(8)),
        'sid' => 'session-id',
    ], $overrides), $secret, 'HS256');
}

/** Token endpoint response for an expiring offline token. */
function tokenResponse(string $token = 'shpat_new', array $overrides = []): array
{
    return array_merge([
        'access_token' => $token,
        'scope' => 'read_products,read_inventory,read_locations,read_orders',
        'expires_in' => 3600,
        'refresh_token' => 'shprt_new',
        'refresh_token_expires_in' => 7776000,
    ], $overrides);
}

/** POST a webhook signed like Shopify does (HMAC-SHA256 of the raw body, base64). */
function postWebhook(string $topic, array $payload = [], string $shop = 'demo.myshopify.com', ?string $webhookId = null, ?string $hmac = null)
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId ?? (string) Str::uuid(),
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac ?? base64_encode(hash_hmac('sha256', $body, TEST_API_SECRET, true)),
    ], content: $body);
}

/** Write bulk-operation style JSONL lines to a temp file and return its path. */
function jsonlFile(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'bulk').'.jsonl';
    file_put_contents($path, implode("\n", array_map('json_encode', $lines))."\n");

    return $path;
}

function gid(string $type, int $id): string
{
    return "gid://shopify/{$type}/{$id}";
}

/** A variant with 120 days of history at $perDay units/day and $stock in one location. */
function product(Shop $shop, Location $loc, string $title, int $stock, int $perDay, array $attrs = []): Variant
{
    $v = Variant::factory()->for($shop)->create($attrs + ['product_title' => $title, 'title' => 'Default Title', 'shopify_created_at' => '2025-01-01']);
    InventoryLevel::factory()->create(['shop_id' => $shop->id, 'variant_id' => $v->id, 'location_id' => $loc->id, 'available' => $stock]);
    $rows = [];
    for ($ago = 1; $ago <= 120; $ago++) {
        $rows[] = ['shop_id' => $shop->id, 'variant_id' => $v->id, 'date' => CarbonImmutable::parse('2026-09-20')->subDays($ago)->toDateString(),
            'units_sold' => $perDay, 'units_returned' => 0, 'end_of_day_stock' => null, 'was_in_stock' => true];
    }
    DailySale::insert($rows);

    return $v;
}
