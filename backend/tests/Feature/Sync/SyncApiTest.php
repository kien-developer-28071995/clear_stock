<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncStage;
use App\Enums\SyncType;
use App\Jobs\Sync\StartSyncRun;
use App\Jobs\Webhooks\HandleBulkOperationFinished;
use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::fake();
    Queue::fake();
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'last_synced_at' => now()->subDay(), 'sync_status' => 'completed']);
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

function makeRun(Shop $shop, array $attrs = []): SyncRun
{
    return SyncRun::create($attrs + [
        'shop_id' => $shop->id, 'type' => SyncType::Initial, 'status' => SyncRunStatus::Running,
        'stage' => SyncStage::Fetching, 'progress' => 30, 'window_start' => '2025-09-20', 'started_at' => now(),
    ]);
}

it('returns the sync status for the progress bar', function () {
    makeRun($this->shop);

    $this->getJson('/api/sync', $this->auth)
        ->assertOk()
        ->assertJsonPath('data.run.stage', 'fetching')
        ->assertJsonPath('data.run.progress', 30)
        ->assertJsonMissingPath('data.run.stage_label'); // the app translates the stage code
});

it('shows fresh progress immediately (cache invalidated on update)', function () {
    $run = makeRun($this->shop);
    $this->getJson('/api/sync', $this->auth)->assertJsonPath('data.run.progress', 30);

    $run->update(['progress' => 45]);

    $this->getJson('/api/sync', $this->auth)->assertJsonPath('data.run.progress', 45);
});

it('starts a manual sync', function () {
    $this->postJson('/api/sync', [], $this->auth)
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'running')
        ->assertJsonPath('data.run.type', 'manual');

    Queue::assertPushed(StartSyncRun::class);
});

it('returns the running sync instead of starting another', function () {
    $run = makeRun($this->shop);

    $this->postJson('/api/sync', [], $this->auth)->assertStatus(202)->assertJsonPath('data.run.id', $run->id);

    Queue::assertNotPushed(StartSyncRun::class);
});

it('rate-limits manual syncs right after one finished', function () {
    makeRun($this->shop, ['status' => SyncRunStatus::Completed, 'stage' => SyncStage::Completed, 'started_at' => now()->subMinute()]);

    $this->postJson('/api/sync', [], $this->auth)->assertStatus(429);
});

it('queues bulk_operations/finish webhooks', function () {
    postWebhook('bulk_operations/finish', ['admin_graphql_api_id' => 'gid://shopify/BulkOperation/3', 'status' => 'completed'])->assertOk();

    Queue::assertPushedOn('webhooks', HandleBulkOperationFinished::class, fn ($j) => $j->operationId === 'gid://shopify/BulkOperation/3');
});

it('starts nightly syncs only for shops whose local time is the nightly hour', function () {
    $this->travelTo('2026-09-20 06:30:00'); // UTC
    $ny = Shop::factory()->create(['timezone' => 'America/New_York', 'last_synced_at' => '2026-09-19 06:00:00']); // 02:30 local
    $tokyo = Shop::factory()->create(['timezone' => 'Asia/Tokyo', 'last_synced_at' => '2026-09-19 06:00:00']);   // 15:30 local
    Shop::factory()->uninstalled()->create(['timezone' => 'America/New_York']);
    $this->shop->update(['timezone' => 'Asia/Tokyo']);

    $this->artisan('sync:nightly')->assertSuccessful();

    expect(SyncRun::forShop($ny)->count())->toBe(1)
        ->and(SyncRun::forShop($tokyo)->count())->toBe(0)
        ->and(SyncRun::count())->toBe(1);
});

it('fails stuck runs and prunes old ones', function () {
    $stuck = makeRun($this->shop, ['started_at' => now()->subHours(7)]);
    $old = makeRun($this->shop, ['status' => SyncRunStatus::Completed, 'stage' => SyncStage::Completed, 'started_at' => now()->subDays(40)]);

    $this->artisan('sync:maintenance')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(SyncRunStatus::Failed)
        ->and(SyncRun::find($old->id))->toBeNull()
        ->and($this->shop->fresh()->sync_error)->toBe(['code' => 'timeout', 'params' => []]);
});
