<?php

use App\Exceptions\ApiException;
use App\Listeners\ReportLongQueueWait;
use App\Models\Shop;
use App\Services\App\DashboardService;
use App\Support\Monitor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;

// Errors (unhandled exceptions, caught system errors, frontend crashes) go to one
// Slack channel, throttled per error, without secrets.

const SLACK_URL = 'https://hooks.slack.test/services/T/B/X';

beforeEach(function () {
    config(['monitoring.slack_webhook_url' => SLACK_URL, 'monitoring.environment' => 'testing', 'monitoring.throttle_seconds' => 600]);
    Http::fake([SLACK_URL => Http::response('ok'), '*' => Http::response(['data' => []])]);
});

function slackPosts(): array
{
    return Http::recorded(fn (Request $r) => $r->url() === SLACK_URL)->map(fn ($pair) => json_encode($pair[0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->values()->all();
}

it('sends errors with the exception, where it happened and a trace', function () {
    Log::error('Something broke', ['exception' => new RuntimeException('Database is on fire'), 'shop' => 'demo.myshopify.com', 'customer_email' => 'a@b.c']);

    $posts = slackPosts();
    expect($posts)->toHaveCount(1)
        ->and($posts[0])->toContain('[testing] ERROR')
        ->toContain('RuntimeException')
        ->toContain('Database is on fire')
        ->toContain('demo.myshopify.com')
        ->toContain('tests/Feature/MonitoringTest.php')
        ->not->toContain('a@b.c'); // only whitelisted context
});

it('masks tokens and secrets', function () {
    Log::error('Token shpat_abc123DEF leaked with Bearer eyJhbGciOi.eyJzdWIi.sig and ?secret=hunter2');

    expect(slackPosts()[0])->toContain('shpat_***')->toContain('Bearer ***')->toContain('secret=***')
        ->not->toContain('abc123DEF')->not->toContain('hunter2');
});

it('sends the same error once per throttle window, then says how often it repeated', function () {
    $boom = fn () => Log::error('Boom', ['exception' => new RuntimeException('same place')]);

    $boom();
    $boom();
    $boom();
    expect(slackPosts())->toHaveCount(1);

    $this->travel(11)->minutes();
    $boom();
    expect(slackPosts())->toHaveCount(2)
        ->and(slackPosts()[1])->toContain('2× since the last alert');
});

it('ignores warnings unless configured, and does nothing without a webhook', function () {
    Log::warning('Just a warning');
    expect(slackPosts())->toBe([]);

    config(['monitoring.level' => 'warning']);
    Log::warning('Now a warning counts');
    expect(slackPosts())->toHaveCount(1);

    config(['monitoring.slack_webhook_url' => null]);
    Log::critical('Nowhere to send');
    expect(slackPosts())->toHaveCount(1);
});

it('reports unhandled API exceptions with the shop and route, but not expected 4xx outcomes', function () {
    Shop::factory()->create(['domain' => 'demo.myshopify.com']);
    $auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->mock(DashboardService::class)->shouldReceive('build')->andThrow(new LogicException('Dashboard exploded'));

    $this->getJson('/api/dashboard', $auth)->assertStatus(500)->assertJsonPath('code', 'server_error');
    $this->getJson('/api/forecasts/999999', $auth)->assertNotFound();     // ApiException
    $this->postJson('/api/onboarding', [], $auth)->assertUnprocessable(); // validation
    $this->getJson('/api/shop', ['Authorization' => 'Bearer nope'])->assertUnauthorized();

    $posts = slackPosts();
    expect($posts)->toHaveCount(1)
        ->and($posts[0])->toContain('Dashboard exploded')->toContain('GET /api/dashboard')->toContain('demo.myshopify.com');
});

it('reports errors caught in try/catch, and keeps expected ones as warnings', function () {
    Monitor::caught(new RuntimeException('Shopify returned garbage'), 'loading shop details', ['shop' => 'demo.myshopify.com']);
    Monitor::expected(new RuntimeException('Shop uninstalled meanwhile'), 'subscription webhook');

    expect(slackPosts())->toHaveCount(1)
        ->and(slackPosts()[0])->toContain('Caught in loading shop details')->toContain('Shopify returned garbage');
});

it('reports frontend errors sent by the app', function () {
    Shop::factory()->create(['domain' => 'demo.myshopify.com']);

    $this->postJson('/api/client-errors', [
        'message' => "Cannot read properties of undefined (reading 'name')",
        'stack' => "TypeError: Cannot read...\n    at ProductDetailPage (index-abc.js:1:200)",
        'url' => '/products/8?id_token=secret',
        'component' => '    at ProductDetailPage',
    ], ['Authorization' => 'Bearer '.sessionToken()])->assertNoContent();

    expect(slackPosts()[0])->toContain('Frontend: Cannot read properties')->toContain('frontend')->toContain('/products/8')
        ->not->toContain('id_token');
});

it('never fails or loops when Slack is down', function () {
    Http::fake([SLACK_URL => Http::response('nope', 500)]);

    Log::error('First', ['exception' => new RuntimeException('a')]);
    Log::error('Second', ['exception' => new LogicException('b')]);

    expect(slackPosts())->toHaveCount(2);
});

it('has a command to send a test alert', function () {
    $this->artisan('monitoring:test')->assertSuccessful();
    expect(slackPosts()[0])->toContain('Test alert from monitoring:test');

    config(['monitoring.slack_webhook_url' => null]);
    $this->artisan('monitoring:test')->assertFailed();
});

it('does not report ApiException even when thrown outside HTTP', function () {
    report(new ApiException('product_not_found', 404));

    expect(slackPosts())->toBe([]);
});

it('alerts when a queue is backed up', function () {
    // Called directly: dispatching the event would also run Horizon's own listeners (real Redis).
    (new ReportLongQueueWait)->handle(new LongWaitDetected('redis', 'webhooks', 95));

    expect(slackPosts())->toHaveCount(1)
        ->and(slackPosts()[0])->toContain('Queue redis:webhooks is backed up')->toContain('Oldest job waited 95s');
});
