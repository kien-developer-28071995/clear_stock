<?php

use App\Enums\StockLevel;
use App\Jobs\SendRealtimeAlerts;
use App\Jobs\SyncRealtimeWebhook;
use App\Jobs\Webhooks\HandleInventoryLevelUpdate;
use App\Mail\RealtimeStockAlertMail;
use App\Models\AlertLog;
use App\Models\AlertSetting;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use App\Models\VariantAlertState;
use App\Services\App\AlertService;
use App\Services\App\RealtimeAlertService;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->travelTo('2026-09-20 10:00:00'); // UTC
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'plan' => 'growth', 'timezone' => 'UTC', 'name' => 'Demo Store', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create(['name' => 'Main']);
    // 4/day, lead time 14 + safety 7 days: reorder point 84, re-armed from 84 + 17 = 101.
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 100, perDay: 4);
    $this->plate = product($this->shop, $this->location, 'Plate', stock: 500, perDay: 1); // reorder point 21
    app(ForecastService::class)->runForShop($this->shop);
    $this->setting = AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test', 'realtime' => 'all']);
    $this->realtime = app(RealtimeAlertService::class);

    // Shopify telling us the stock of $variant at the only location is now $available.
    $this->stock = function (Variant $variant, int $available, ?string $updatedAt = null): void {
        $this->realtime->handleInventoryUpdate($this->shop->fresh(), [
            'inventory_item_id' => $variant->inventory_item_id,
            'location_id' => $this->location->shopify_location_id,
            'available' => $available,
            'updated_at' => $updatedAt ?? now()->toIso8601String(),
        ]);
    };
    $this->flush = fn (?string $utc = null) => $this->realtime->flush($this->shop->fresh(), $utc ? CarbonImmutable::parse($utc, 'UTC') : null);
    $this->level = fn (Variant $variant) => VariantAlertState::where('variant_id', $variant->id)->first()?->level->value;
});

it('queues inventory_levels/update webhooks with only the fields it needs', function () {
    postWebhook('inventory_levels/update', ['inventory_item_id' => 5, 'location_id' => 7, 'available' => 3, 'updated_at' => '2026-09-20T10:00:00Z', 'extra' => 'x'])->assertOk();

    Queue::assertPushedOn('webhooks', HandleInventoryLevelUpdate::class, fn ($job) => $job->shopDomain === 'demo.myshopify.com'
        && $job->payload === ['inventory_item_id' => 5, 'location_id' => 7, 'available' => 3, 'updated_at' => '2026-09-20T10:00:00Z']);
});

it('alerts once when stock crosses the reorder point, not on every sale', function () {
    ($this->stock)($this->mug, 90);
    expect(($this->level)($this->mug))->toBeNull(); // above the reorder point: nothing stored
    Queue::assertNothingPushed();

    ($this->stock)($this->mug, 84);
    expect(($this->level)($this->mug))->toBe('low');
    Queue::assertPushed(SendRealtimeAlerts::class, fn ($job) => $job->shopId === $this->shop->id && $job->delay !== null);

    expect(($this->flush)())->toBe('sent');
    Mail::assertQueued(RealtimeStockAlertMail::class, function (RealtimeStockAlertMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('owner@demo.test')
            && $mail->envelope()->subject === 'Mug is running low · Demo Store'
            && str_contains($html, '/admin/apps/'.TEST_API_KEY.'/products/'.$this->mug->id)
            && str_contains($html, 'Sells 4/day');
    });
    expect(AlertLog::where('type', 'realtime')->count())->toBe(1)
        ->and(AlertLog::where('variant_id', $this->mug->id)->value('type')->value)->toBe('reorder_needed');

    // More sales while already low: no new pending transition, no email.
    ($this->stock)($this->mug, 70);
    ($this->stock)($this->mug, 60);
    expect(($this->flush)())->toBe('nothing_pending');
    Mail::assertQueuedCount(1);
});

it('batches every product that crossed a threshold into one email', function () {
    ($this->stock)($this->mug, 0);
    ($this->stock)($this->plate, 20);

    expect(($this->flush)())->toBe('sent');
    Mail::assertQueuedCount(1);
    Mail::assertQueued(RealtimeStockAlertMail::class, fn ($mail) => count($mail->items) === 2
        && $mail->items[0]['name'] === 'Mug' && $mail->items[0]['out_of_stock'] // sold out first
        && $mail->envelope()->subject === '2 products need attention (1 sold out) · Demo Store');
});

it('needs a real restock before a product can alert again (hysteresis + cooldown)', function () {
    ($this->stock)($this->mug, 80);
    ($this->flush)();

    // A return or two pushes it just above the reorder point and back: still "low", no email.
    ($this->stock)($this->mug, 86);
    expect(($this->level)($this->mug))->toBe('low');
    ($this->stock)($this->mug, 83);
    expect(($this->flush)())->toBe('nothing_pending');

    // Restocked well above: re-armed. Dropping again within the week is still covered by the cooldown.
    ($this->stock)($this->mug, 101);
    expect(($this->level)($this->mug))->toBe('ok');
    ($this->stock)($this->mug, 80);
    expect(($this->flush)())->toBe('nothing_new');

    // A week later it is news again.
    $this->travelTo('2026-09-28 10:00:00');
    ($this->stock)($this->mug, 150);
    ($this->stock)($this->mug, 80);
    expect(($this->flush)())->toBe('sent');
    Mail::assertQueuedCount(2);
});

it('reports a sell-out after a low-stock email once, then stays quiet', function () {
    ($this->stock)($this->mug, 50);
    ($this->flush)();

    ($this->stock)($this->mug, 0);
    expect(($this->flush)())->toBe('sent');

    // Back to 1 and sold out again the same week: nothing new.
    ($this->stock)($this->mug, 1);
    expect(($this->level)($this->mug))->toBe('low');
    ($this->stock)($this->mug, 0);
    expect(($this->flush)())->toBe('nothing_new');
    Mail::assertQueuedCount(2);
});

it('does not repeat what the daily digest already reported', function () {
    ($this->stock)($this->mug, 80);
    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect(app(AlertService::class)->sendIfDue($this->shop->fresh(), CarbonImmutable::parse('2026-09-20 09:00', 'UTC')))->toBe('sent');

    expect(($this->flush)())->toBe('nothing_new');
    Mail::assertQueuedCount(1);
});

it('only reports sell-outs in out-of-stock mode', function () {
    $this->setting->update(['realtime' => 'out_of_stock']);

    ($this->stock)($this->mug, 80);
    expect(($this->flush)())->toBe('nothing_new');

    ($this->stock)($this->mug, 0);
    expect(($this->flush)())->toBe('sent');
    Mail::assertQueued(RealtimeStockAlertMail::class, fn ($mail) => $mail->items[0]['out_of_stock']);
});

it('never mentions a muted product, in real-time or in the digest', function () {
    $this->mug->update(['alerts_muted' => true]);

    ($this->stock)($this->mug, 0);
    expect(($this->flush)())->toBe('nothing_new');

    app(ForecastService::class)->runForShop($this->shop->fresh());
    expect(app(AlertService::class)->sendIfDue($this->shop->fresh(), CarbonImmutable::parse('2026-09-20 09:00', 'UTC')))->toBe('nothing_new');
    Mail::assertNothingQueued();
});

it('holds emails during the night and sends them in the morning', function () {
    ($this->stock)($this->mug, 0);

    expect(($this->flush)('2026-09-20 22:30'))->toBe('quiet_hours')
        ->and(($this->flush)('2026-09-21 06:59'))->toBe('quiet_hours');
    Mail::assertNothingQueued();

    expect(($this->flush)('2026-09-21 07:00'))->toBe('sent');
});

it('caps real-time emails per shop per day', function () {
    config(['alerts.realtime.max_per_day' => 1]);
    ($this->stock)($this->mug, 0);
    ($this->flush)();

    ($this->stock)($this->plate, 10);
    expect(($this->flush)())->toBe('daily_cap');
    expect(VariantAlertState::where('variant_id', $this->plate->id)->value('pending_at'))->not->toBeNull(); // kept for tomorrow

    expect(($this->flush)('2026-09-21 08:00'))->toBe('sent');
});

it('picks up held alerts from the hourly alerts:send', function () {
    ($this->stock)($this->mug, 0);
    Queue::fake(); // forget the delayed job

    $this->artisan('alerts:send')->assertSuccessful();

    Queue::assertPushed(SendRealtimeAlerts::class, fn ($job) => $job->shopId === $this->shop->id);
});

it('ignores stock updates delivered out of order', function () {
    ($this->stock)($this->mug, 0, '2026-09-20T10:05:00Z');
    ($this->stock)($this->mug, 95, '2026-09-20T10:01:00Z'); // older, delivered late

    expect(InventoryLevel::where('variant_id', $this->mug->id)->value('available'))->toBe(0)
        ->and(($this->level)($this->mug))->toBe('out');
});

it('sums every active location and ignores products that do not sell', function () {
    $second = Location::factory()->for($this->shop)->create(['name' => 'Backroom']);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $this->mug->id, 'location_id' => $second->id, 'available' => 50]);

    ($this->stock)($this->mug, 0); // 0 here + 50 in the backroom: low, not out
    expect(($this->level)($this->mug))->toBe('low');
    ($this->flush)();
    Mail::assertQueued(RealtimeStockAlertMail::class, fn ($mail) => $mail->items[0]['stock'] === 50
        && $mail->items[0]['locations'] === [['name' => 'Backroom', 'available' => 50], ['name' => 'Main', 'available' => 0]]);

    $idle = Variant::factory()->for($this->shop)->create(['product_title' => 'Idle']);
    InventoryLevel::factory()->create(['shop_id' => $this->shop->id, 'variant_id' => $idle->id, 'location_id' => $this->location->id, 'available' => 3]);
    app(ForecastService::class)->runForShop($this->shop->fresh());
    ($this->stock)($idle, 0);
    expect(($this->level)($idle))->toBeNull();
});

it('does nothing outside Growth or when turned off', function (array $shopAttrs, array $settingAttrs) {
    $this->shop->update($shopAttrs);
    $this->setting->update($settingAttrs);

    ($this->stock)($this->mug, 0);

    expect(VariantAlertState::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'starter plan' => [['plan' => 'starter'], []],
    'real-time off' => [[], ['realtime' => 'off']],
    'alerts off' => [[], ['enabled' => false]],
    'no email' => [[], ['email' => null]],
]);

it('drops pending alerts when the shop leaves Growth before they are sent', function () {
    ($this->stock)($this->mug, 0);
    $this->shop->update(['plan' => 'starter']);

    expect(($this->flush)())->toBe('inactive')
        ->and(VariantAlertState::whereNotNull('pending_at')->count())->toBe(0);
    Mail::assertNothingQueued();
});

describe('webhook subscription', function () {
    beforeEach(function () {
        $this->calls = [];
        $this->existing = [];
        Http::fake(function (Request $r) {
            $q = $r['query'] ?? '';
            $this->calls[] = $q;

            return match (true) {
                str_contains($q, 'query WebhookSubscriptions') => Http::response(['data' => ['webhookSubscriptions' => ['nodes' => $this->existing]]]),
                str_contains($q, 'WebhookSubscriptionCreate') => Http::response(['data' => ['webhookSubscriptionCreate' => [
                    'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/9'], 'userErrors' => [],
                ]]]),
                str_contains($q, 'WebhookSubscriptionDelete') => Http::response(['data' => ['webhookSubscriptionDelete' => [
                    'deletedWebhookSubscriptionId' => 'gid://shopify/WebhookSubscription/1', 'userErrors' => [],
                ]]]),
            };
        });
        $this->uri = rtrim(config('app.url'), '/').'/webhooks';
    });

    it('subscribes and takes today\'s levels as the starting point', function () {
        InventoryLevel::where('variant_id', $this->mug->id)->update(['available' => 10]);
        app(ForecastService::class)->runForShop($this->shop->fresh());

        $this->realtime->syncSubscription($this->shop->fresh());

        expect($this->shop->fresh()->realtime_webhook_id)->toBe('gid://shopify/WebhookSubscription/9');
        Http::assertSent(fn (Request $r) => str_contains($r['query'] ?? '', 'WebhookSubscriptionCreate')
            && $r['variables']['topic'] === 'INVENTORY_LEVELS_UPDATE' && $r['variables']['webhookSubscription']['uri'] === $this->uri);
        // Already low before alerts were on: not news.
        expect(VariantAlertState::where('variant_id', $this->mug->id)->first())
            ->level->toBe(StockLevel::Low)->pending_at->toBeNull();
    });

    it('keeps a matching subscription and replaces one pointing at an old URL', function () {
        $this->existing = [['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'INVENTORY_LEVELS_UPDATE', 'uri' => 'https://old.example/webhooks']];

        $this->realtime->syncSubscription($this->shop->fresh());
        expect(collect($this->calls)->filter(fn ($q) => str_contains($q, 'WebhookSubscriptionDelete')))->toHaveCount(1)
            ->and($this->shop->fresh()->realtime_webhook_id)->toBe('gid://shopify/WebhookSubscription/9');

        $this->calls = [];
        $this->existing = [['id' => 'gid://shopify/WebhookSubscription/9', 'topic' => 'INVENTORY_LEVELS_UPDATE', 'uri' => $this->uri]];
        $this->realtime->syncSubscription($this->shop->fresh());
        expect($this->calls)->toHaveCount(1); // only the list query
    });

    it('unsubscribes when the shop is no longer on Growth', function () {
        $this->shop->update(['plan' => 'starter', 'realtime_webhook_id' => 'gid://shopify/WebhookSubscription/1']);
        $this->existing = [['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'INVENTORY_LEVELS_UPDATE', 'uri' => $this->uri]];

        $this->realtime->syncSubscription($this->shop->fresh());

        Http::assertSent(fn (Request $r) => str_contains($r['query'] ?? '', 'WebhookSubscriptionDelete') && $r['variables']['id'] === 'gid://shopify/WebhookSubscription/1');
        expect($this->shop->fresh()->realtime_webhook_id)->toBeNull();
    });

    it('reconciles every shop that uses or still holds a subscription, daily', function () {
        $downgraded = Shop::factory()->create(['plan' => 'starter', 'realtime_webhook_id' => 'gid://shopify/WebhookSubscription/5']);
        Shop::factory()->create(['plan' => 'growth']); // never turned it on

        $this->artisan('alerts:realtime-sync')->assertSuccessful();

        Queue::assertPushed(SyncRealtimeWebhook::class, 2);
        Queue::assertPushed(SyncRealtimeWebhook::class, fn ($job) => $job->shopId === $downgraded->id);
    });
});

describe('api', function () {
    beforeEach(fn () => $this->auth = ['Authorization' => 'Bearer '.sessionToken()]);

    it('turns real-time alerts on from Settings and syncs the webhook', function () {
        $this->setting->update(['realtime' => 'off']);

        $this->putJson('/api/settings', ['alerts' => ['realtime' => 'out_of_stock']], $this->auth)
            ->assertOk()
            ->assertJsonPath('data.alerts.realtime', 'out_of_stock')
            ->assertJsonPath('data.alerts.realtime_available', true);
        Queue::assertPushed(SyncRealtimeWebhook::class, fn ($job) => $job->shopId === $this->shop->id);

        // Switching between modes keeps the same subscription.
        Queue::fake();
        $this->putJson('/api/settings', ['alerts' => ['realtime' => 'all']], $this->auth)->assertOk();
        Queue::assertNotPushed(SyncRealtimeWebhook::class);

        $this->putJson('/api/settings', ['alerts' => ['realtime' => 'hourly']], $this->auth)->assertUnprocessable();
    });

    it('shows real-time alerts as unavailable below Growth', function () {
        $this->shop->update(['plan' => 'starter']);

        $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.alerts.realtime_available', false);
        $this->putJson('/api/settings', ['alerts' => ['realtime' => 'all', 'email' => 'x@demo.test']], $this->auth)->assertOk();
        Queue::assertNotPushed(SyncRealtimeWebhook::class);
    });

    it('mutes one product from its settings', function () {
        $this->putJson("/api/variants/{$this->mug->id}/settings", ['alerts_muted' => true], $this->auth)
            ->assertOk()->assertJsonPath('data.alerts_muted', true);

        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)->assertJsonPath('data.settings.alerts_muted', true);
    });
});
