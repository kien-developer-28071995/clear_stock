<?php

use App\Jobs\SendSupplierOrders;
use App\Mail\SupplierOrderMail;
use App\Models\AlertSetting;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\SupplierEmail;
use App\Models\Variant;
use App\Services\App\SupplierEmailService;
use App\Services\Forecast\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// Growth: email a supplier a purchase order, reviewed by the merchant or automatic
// (opt-in per supplier, at most once a week). Replies go to the merchant.

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    Http::fake();
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'name' => 'Demo Store', 'timezone' => 'UTC', 'plan' => 'growth', 'access_token_expires_at' => now()->addYear()]);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
    $this->acme = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'email' => 'orders@acme.test', 'lead_time_days' => null]);
    $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['supplier_id' => $this->acme->id, 'sku' => 'MUG-1']);     // due
    $this->plate = product($this->shop, $this->location, 'Plate', stock: 5000, perDay: 1, attrs: ['supplier_id' => $this->acme->id]); // not due
    app(ForecastService::class)->runForShop($this->shop);
    AlertSetting::factory()->for($this->shop)->create(['email' => 'owner@demo.test']);
});

it('drafts an email with the supplier products that are due', function () {
    $this->getJson("/api/suppliers/{$this->acme->id}/email", $this->auth)
        ->assertOk()
        ->assertJsonPath('data.to', 'orders@acme.test')
        ->assertJsonPath('data.reply_to', 'owner@demo.test')
        ->assertJsonPath('data.items', [['variant_id' => $this->mug->id, 'name' => 'Mug', 'sku' => 'MUG-1', 'quantity' => 194]])
        ->assertJsonPath('data.last_emailed_at', null);

    // Only the picked products (with something to order: Plate has plenty of stock).
    $this->getJson("/api/suppliers/{$this->acme->id}/email?variant_ids={$this->mug->id},{$this->plate->id}", $this->auth)
        ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.name', 'Mug');
});

it('sends the edited purchase order to the supplier, with replies to the merchant', function () {
    $this->postJson("/api/suppliers/{$this->acme->id}/email", [
        'items' => [['variant_id' => $this->mug->id, 'quantity' => 200], ['variant_id' => $this->plate->id, 'quantity' => 24]],
        'message' => "Please ship by <b>Friday</b>.\nThanks!",
    ], $this->auth)->assertStatus(202)->assertJsonPath('data.total_units', 224);

    Mail::assertQueued(SupplierOrderMail::class, function (SupplierOrderMail $mail) {
        $html = $mail->render();
        $csv = $mail->attachments()[0];

        return $mail->hasTo('orders@acme.test')
            && $mail->envelope()->replyTo[0]->address === 'owner@demo.test'
            && $mail->envelope()->subject === 'Purchase order from Demo Store · 2026-09-20'
            && str_contains($html, 'MUG-1') && str_contains($html, '200') && str_contains($html, 'Plate')
            && str_contains($html, 'Please ship by &lt;b&gt;Friday&lt;/b&gt;.') // escaped
            && $csv->as === 'purchase-order-2026-09-20.csv';
    });
    expect(SupplierEmail::first())->toMatchArray(['trigger' => 'manual', 'to_email' => 'orders@acme.test', 'total_units' => 224]);
    $this->getJson('/api/suppliers', $this->auth)->assertJsonPath('data.0.last_emailed_at', now()->toIso8601String());
});

it('refuses double sends, empty orders, other shops products and suppliers without email', function () {
    $send = fn (array $items) => $this->postJson("/api/suppliers/{$this->acme->id}/email", ['items' => $items], $this->auth);

    $send([['variant_id' => $this->mug->id, 'quantity' => 1]])->assertStatus(202);
    $send([['variant_id' => $this->mug->id, 'quantity' => 1]])->assertStatus(429)->assertJsonPath('code', 'supplier_email_too_soon');

    $foreign = Variant::factory()->create(); // another shop
    $this->travel(2)->minutes();
    $send([['variant_id' => $foreign->id, 'quantity' => 5]])->assertUnprocessable()->assertJsonPath('errors.items.0.code', 'no_items');

    $this->acme->update(['email' => null]);
    $send([['variant_id' => $this->mug->id, 'quantity' => 1]])->assertUnprocessable()->assertJsonPath('code', 'supplier_has_no_email');
    Mail::assertQueuedCount(1);
});

it('sends by hand from Starter, automatic emails are Growth only', function () {
    $this->shop->update(['plan' => 'starter']);

    $this->getJson("/api/suppliers/{$this->acme->id}/email", $this->auth)->assertOk();
    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'auto_email' => true], $this->auth)
        ->assertStatus(402)->assertJsonPath('params.plan', 'growth')->assertJsonPath('params.feature', 'supplier_auto_email');

    $this->shop->update(['plan' => 'free']);
    $this->getJson("/api/suppliers/{$this->acme->id}/email", $this->auth)->assertStatus(402)->assertJsonPath('params.plan', 'starter');
});

it('turns automatic emails on only with a supplier email, and off when the email is removed', function () {
    $noEmail = Supplier::factory()->for($this->shop)->create(['email' => null]);
    $this->putJson("/api/suppliers/{$noEmail->id}", ['name' => $noEmail->name, 'auto_email' => true], $this->auth)
        ->assertUnprocessable()->assertJsonPath('errors.auto_email.0.code', 'auto_email_needs_email');

    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'email' => 'orders@acme.test', 'auto_email' => true], $this->auth)
        ->assertOk()->assertJsonPath('data.auto_email', true);
    $this->putJson("/api/suppliers/{$this->acme->id}", ['name' => 'Acme', 'email' => null], $this->auth)
        ->assertOk()->assertJsonPath('data.auto_email', false);
});

describe('automatic emails', function () {
    beforeEach(function () {
        $this->acme->update(['auto_email' => true]);
        $this->service = app(SupplierEmailService::class);
        $this->at = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC');
    });

    it('sends what is due once a week, from 8am shop time, only to suppliers that opted in', function () {
        Supplier::factory()->for($this->shop)->create(['email' => 'quiet@b.test']); // not opted in

        expect($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-20 07:00')))->toBe(0) // before 8am
            ->and($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-20 09:00')))->toBe(1)
            ->and($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-20 15:00')))->toBe(0); // this week already

        Mail::assertQueued(SupplierOrderMail::class, fn ($m) => $m->hasTo('orders@acme.test') && count($m->items) === 1);
        expect(SupplierEmail::first()->trigger)->toBe('auto');

        $this->travelTo('2026-09-28 06:00:00');
        app(ForecastService::class)->runForShop($this->shop->fresh());
        expect($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-28 09:00')))->toBe(1);
    });

    it('stays quiet without anything due, with stale forecasts or off plan', function () {
        $this->mug->update(['supplier_id' => null]);
        expect($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-20 09:00')))->toBe(0);

        $this->mug->update(['supplier_id' => $this->acme->id]);
        $this->shop->update(['forecasted_at' => '2026-09-18 00:00:00']);
        expect($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-20 09:00')))->toBe(0);

        $this->shop->update(['forecasted_at' => now(), 'plan' => 'starter']);
        expect($this->service->sendDue($this->shop->fresh(), ($this->at)('2026-09-20 09:00')))->toBe(0);
        Mail::assertNothingQueued();
    });

    it('is queued hourly for shops with opted-in suppliers', function () {
        Supplier::factory()->create(['auto_email' => true, 'email' => null]); // no email: skipped

        $this->artisan('suppliers:send-orders')->assertSuccessful();

        Queue::assertPushed(SendSupplierOrders::class, 1);
        Queue::assertPushed(SendSupplierOrders::class, fn ($job) => $job->shopId === $this->shop->id);
    });
});
