<?php

use App\Exceptions\SyncFailedException;
use App\Mail\SyncFailingMail;
use App\Models\AlertSetting;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Services\Sync\SyncService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'name' => 'Demo', 'timezone' => 'UTC', 'last_synced_at' => '2026-09-18 02:00:00']);
    AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test', 'enabled' => false]);
});

/** A sync run that fails (the job runs synchronously in tests). */
function failSync(Shop $shop, string $code = 'timeout'): void
{
    $run = SyncRun::create(['shop_id' => $shop->id, 'type' => 'nightly', 'status' => 'running', 'stage' => 'fetching', 'window_start' => '2026-08-20', 'started_at' => now()]);
    app(SyncService::class)->fail($run->id, new SyncFailedException($code));
}

function completedSync(Shop $shop): void
{
    SyncRun::create(['shop_id' => $shop->id, 'type' => 'nightly', 'status' => 'completed', 'stage' => 'completed', 'window_start' => '2026-08-20', 'started_at' => now()]);
    $shop->update(['sync_failure_notified_at' => null, 'last_synced_at' => now()]);
}

it('emails once when syncing keeps failing, even with alerts turned off', function () {
    failSync($this->shop);
    Mail::assertNothingQueued();                      // one failure is not a problem yet

    failSync($this->shop);
    Mail::assertQueued(SyncFailingMail::class, fn (SyncFailingMail $m) => $m->hasTo('owner@demo.test') && $m->failures === 2);
    expect($this->shop->fresh()->sync_failure_notified_at)->not->toBeNull();

    failSync($this->shop);
    failSync($this->shop);
    Mail::assertQueuedCount(1);                       // same streak: no more emails
});

it('emails again only after syncing worked in between', function () {
    failSync($this->shop);
    failSync($this->shop);
    completedSync($this->shop);
    $this->travel(2)->days();

    failSync($this->shop);
    Mail::assertQueuedCount(1);
    failSync($this->shop);
    Mail::assertQueuedCount(2);
});

it('stays quiet while the last successful sync is recent', function () {
    $this->shop->update(['last_synced_at' => now()->subHours(6)]);

    failSync($this->shop);
    failSync($this->shop);

    Mail::assertNothingQueued();
});

it('says how to fix an expired access and renders', function () {
    $this->shop->update(['sync_error' => ['code' => 'reauthorize', 'params' => []]]);

    $html = (new SyncFailingMail($this->shop->fresh(), 3))->render();
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES);

    expect($text)->toContain("We can't sync Demo")
        ->toContain('Opening the app once renews it')
        ->toContain('based on data from Sep 18, 2026')
        ->and($html)->toContain('/support');
});

it('skips uninstalled shops', function () {
    $this->shop->update(['uninstalled_at' => now(), 'access_token' => null]);

    failSync($this->shop->fresh());
    failSync($this->shop->fresh());

    Mail::assertNothingQueued();
});
