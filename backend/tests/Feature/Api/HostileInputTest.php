<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\Forecast\ForecastService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Whatever a client sends, the API answers with a decision (2xx, 4xx), never with a crash (5xx).
// Every API route is called with every kind of nonsense below; a new route is covered automatically.

beforeEach(function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => []])]);
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'growth', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->supplier = Supplier::factory()->for($this->shop)->create();
    $this->variant = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
    app(ForecastService::class)->runForShop($this->shop);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    // Hundreds of calls in one test: the rate limits are not what is tested here.
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** @return array<int, array{0: string, 1: string}> [method, uri] of every API route, ids filled in */
function apiRoutes(array $ids): array
{
    $out = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $out[] = [$method, '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($ids[$m[1]] ?? 1), $route->uri())];
        }
    }

    return $out;
}

it('never answers a hostile request with a server error', function (array $payload) {
    $failures = [];
    foreach ([
        ['variant' => $this->variant->id, 'supplier' => $this->supplier->id, 'product' => $this->variant->shopify_product_id],   // real ids
        ['variant' => 99999999, 'supplier' => 99999999, 'product' => 99999999, 'order' => 99999999, 'event' => 99999999, 'view' => 99999999], // unknown ids
    ] as $ids) {
        foreach (apiRoutes($ids) as [$method, $uri]) {
            // A query string carries at most a few hundred values (PHP itself stops at 1000).
            $query = array_map(fn ($v) => is_array($v) ? array_slice($v, 0, 5, true) : $v, $payload);
            try {
                $response = $method === 'GET'
                    ? $this->json('GET', $uri.'?'.http_build_query($query), [], $this->auth)
                    : $this->json($method, $uri, $payload, $this->auth);
                $status = $response->getStatusCode();
                // A download is written while it is sent: a failure in there counts too.
                $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
            } catch (Throwable $e) {
                $status = 500;
                $body = get_class($e).': '.$e->getMessage();
            }
            if ($status >= 500) {
                $failures[] = "{$method} {$uri} -> {$status} ".substr($body, 0, 200);
            }
        }
    }

    expect($failures)->toBe([]);
})->with([
    'nothing' => [[]],
    'wrong types' => [[
        'page' => 'abc', 'per_page' => -5, 'status' => ['x'], 'sort' => 'DROP TABLE', 'search' => ['a' => 'b'], 'weeks' => 'many', 'growth' => 'lots', 'horizon' => [],
        'days' => -1, 'location_id' => 'x', 'supplier_id' => 'x', 'vendor' => ['x'], 'abc' => 'Z', 'trend' => 1, 'missing' => 'maybe', 'tab' => [],
        'name' => ['array'], 'email' => 'not-an-email', 'lead_time_days' => 'soon', 'items' => 'none', 'quantity' => 'a few', 'budget' => 'plenty',
        'default_lead_time_days' => [], 'alerts' => 'yes', 'filters' => 'all', 'starts_on' => 'tomorrow', 'ends_on' => 12, 'multiplier' => 'double',
        'costs' => 'cheap', 'excluded_ids' => 'none', 'variant_ids' => 'all', 'suppliers' => 'any', 'plan' => 'platinum', 'interval' => 'forever',
        'status_x' => null, 'received_quantity' => 'some', 'components' => 'x', 'bundle_variant_id' => 'x', 'file' => 'not a file', 'mapping' => 'x',
    ]],
    'extremes' => [[
        'page' => 999999999, 'per_page' => 999999999, 'weeks' => 100000, 'growth' => 1.0e12, 'horizon' => 999999, 'days' => 999999,
        'name' => str_repeat('x', 70000), 'search' => str_repeat('%_\\', 3000), 'email' => str_repeat('a', 400).'@example.com',
        'lead_time_days' => PHP_INT_MAX, 'quantity' => PHP_INT_MAX, 'budget' => 1.0e300, 'default_lead_time_days' => -PHP_INT_MAX,
        'items' => array_fill(0, 300, ['variant_id' => PHP_INT_MAX, 'quantity' => -1]), 'multiplier' => -3, 'starts_on' => '9999-99-99', 'ends_on' => '0000-00-00',
        'costs' => array_fill(0, 300, ['variant_id' => 'x', 'cost' => -1]), 'excluded_ids' => array_fill(0, 500, -1), 'filters' => array_fill_keys(range(1, 200), str_repeat('y', 500)),
        'received_quantity' => -PHP_INT_MAX, 'variant_ids' => array_fill(0, 5000, 1), 'location_id' => PHP_INT_MAX, 'supplier_id' => -1,
    ]],
    'markup and odd text' => [[
        'name' => "<script>alert(1)</script>\u{0000}\u{202E}'; DROP TABLE shops;--", 'search' => "' OR 1=1 --", 'vendor' => "\u{FFFF}\u{D7FF}😀", 'reference' => "=cmd|' /C calc'!A0",
        'email' => "a@b.c\r\nBcc: x@y.z", 'sort' => 'name; select', 'status' => 'reorder_now" or ""="', 'note' => str_repeat('𝔘', 5000), 'supplier_sku' => "\t\n\r",
        'slack_webhook_url' => 'javascript:alert(1)', 'locale' => '../../etc/passwd', 'starts_on' => "2026-01-01' --", 'tab' => '<img src=x onerror=1>',
    ]],
]);

it('refuses every API route without a session token', function () {
    $open = [];
    foreach (apiRoutes(['variant' => $this->variant->id, 'supplier' => $this->supplier->id]) as [$method, $uri]) {
        $status = $this->json($method, $uri)->getStatusCode();
        if ($status !== 401) {
            $open[] = "{$method} {$uri} -> {$status}";
        }
    }

    expect($open)->toBe([]);
});

it('never shows one shop the rows of another, whatever id is asked for', function () {
    $other = Shop::factory()->create(['domain' => 'other.myshopify.com', 'timezone' => 'UTC', 'plan' => 'growth']);
    $otherLocation = Location::factory()->for($other)->create();
    $theirs = product($other, $otherLocation, 'Secret product', stock: 3, perDay: 2);
    $theirSupplier = Supplier::factory()->for($other)->create(['name' => 'Secret supplier']);
    app(ForecastService::class)->runForShop($other);

    $leaks = [];
    foreach (apiRoutes(['variant' => $theirs->id, 'supplier' => $theirSupplier->id, 'product' => $theirs->shopify_product_id]) as [$method, $uri]) {
        $response = $this->json($method, $uri, ['lead_time_override' => 5, 'name' => 'Taken over', 'status' => 'cancelled'], $this->auth);
        if (str_contains((string) $response->getContent(), 'Secret')) {
            $leaks[] = "{$method} {$uri} shows the other shop's data";
        }
    }

    expect($leaks)->toBe([])
        ->and($theirs->fresh()->lead_time_override)->toBeNull()
        ->and($theirSupplier->fresh()->name)->toBe('Secret supplier');
});

it('takes any file a merchant uploads without a server error', function (string $name, string $content) {
    $file = fn () => UploadedFile::fake()->createWithContent($name, $content);
    $failures = [];
    foreach ([
        ['/api/costs/import', ['file' => $file()]],
        ['/api/imports/purchase-orders/preview', ['file' => $file()]],
        ['/api/imports/purchase-orders/apply', ['file' => $file(), 'mapping' => ['supplier' => 0, 'sku' => 1, 'ordered_at' => 2, 'received_at' => 3]]],
        ['/api/imports/purchase-orders/apply', ['file' => $file(), 'mapping' => ['supplier' => 999, 'sku' => -1, 'ordered_at' => 'x', 'received_at' => null]]],
    ] as [$url, $body]) {
        try {
            $status = $this->post($url, $body, $this->auth + ['Accept' => 'application/json'])->getStatusCode();
        } catch (Throwable $e) {
            $status = 500;
        }
        if ($status >= 500) {
            $failures[] = "{$url} with {$name} -> {$status}";
        }
    }

    expect($failures)->toBe([]);
})->with([
    'empty' => ['empty.csv', ''],
    'header only' => ['head.csv', "SKU,Cost\n"],
    'no line break' => ['one.csv', 'SKU,Cost'],
    'binary' => ['image.csv', random_bytes(4000)],
    'not utf-8' => ['latin.csv', "SKU;Co\xFBt\nM\xFCg;4,50\n"],
    'bom and semicolons' => ['bom.csv', "\xEF\xBB\xBFSKU;Cost\r\nSKU-1;4,50\r\n"],
    'formulas and markup' => ['evil.csv', "SKU,Cost,Supplier,Ordered\n=cmd|' /C calc'!A0,<script>alert(1)</script>,@SUM(1+1),+1\n\"un\"\"closed,1\n"],
    'huge cells and numbers' => ['huge.csv', "SKU,Cost\n".str_repeat('x', 200000).",1e999\nSKU-1,-5\nSKU-1,99999999999999999999\nSKU-1,abc\n"],
    'ragged rows' => ['ragged.csv', "a,b,c\n1\n1,2,3,4,5,6,7,8,9\n,,,\n\n\n"],
    'many rows' => ['many.csv', "SKU,Cost,Supplier,Ordered,Received\n".str_repeat("SKU-1,4.5,Acme,2026-01-01,2026-01-20\n", 6000)],
    'wrong type of file' => ['doc.pdf', '%PDF-1.7 not a csv'],
]);
