<?php

use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\BundleComponent;
use App\Models\Forecast;
use App\Models\ForecastOverride;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Variant;
use App\Services\Forecast\ForecastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/graphql.json' => Http::response(['data' => ['shop' => ['contactEmail' => 'owner@demo.test']]])]);
    $this->travelTo('2026-09-20 10:00:00');
    // Starter: all features used below except the Growth-only ones (see PlanGatingTest).
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'starter']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

function forecastAll(Shop $shop): void
{
    app(ForecastService::class)->runForShop($shop);
}

describe('onboarding', function () {
    it('pre-fills the default lead time and the store contact email', function () {
        $this->getJson('/api/onboarding', $this->auth)
            ->assertOk()
            ->assertJsonPath('data', ['onboarded' => false, 'lead_time_days' => 14, 'alert_email' => 'owner@demo.test']);
    });

    it('saves the answers and recomputes when the lead time changed', function () {
        $this->postJson('/api/onboarding', ['lead_time_days' => 21, 'alert_email' => 'alerts@demo.test'], $this->auth)
            ->assertOk()
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.alert_email', 'alerts@demo.test');

        expect($this->shop->fresh()->default_lead_time_days)->toBe(21)
            ->and($this->shop->fresh()->alertSetting->enabled)->toBeTrue();
        Queue::assertPushed(RecomputeForecasts::class);
    });

    it('validates the answers', function () {
        $this->postJson('/api/onboarding', ['lead_time_days' => 0, 'alert_email' => 'nope'], $this->auth)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead_time_days', 'alert_email']);
    });
});

describe('dashboard', function () {
    it('shows what to reorder now and cash tied up in slow stock', function () {
        product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);                  // reorder now
        product($this->shop, $this->location, 'Plate', stock: 500, perDay: 4);               // healthy
        product($this->shop, $this->location, 'Vase', stock: 30, perDay: 0, attrs: ['unit_cost' => 12.5]); // no sales
        forecastAll($this->shop);

        $this->getJson('/api/dashboard', $this->auth)
            ->assertOk()
            ->assertJsonPath('data.counts.total', 3)
            ->assertJsonPath('data.counts.reorder_now', 1)
            ->assertJsonPath('data.counts.slow', 1)
            ->assertJsonPath('data.actions.order_today.0.name', 'Mug')
            ->assertJsonPath('data.actions.order_today.0.reason', ['code' => 'sells_over_window', 'params' => ['avg' => 4, 'count' => 30]])
            ->assertJsonPath('data.actions.out_of_stock', [])
            ->assertJsonPath('data.runway.0.name', 'Mug')
            ->assertJsonPath('data.runway.0.reorder_days', 21)
            ->assertJsonPath('data.slow_movers.value', 375)
            ->assertJsonPath('data.slow_movers.top.0.name', 'Vase')
            ->assertJsonPath('data.currency', 'USD');
    });

    it('groups the to-do list into out of stock, order today and this week', function () {
        product($this->shop, $this->location, 'Empty', stock: 0, perDay: 2);
        product($this->shop, $this->location, 'Soon', stock: 100, perDay: 4);   // reorder point 84 -> in 4 days
        product($this->shop, $this->location, 'Later', stock: 400, perDay: 4);  // in 79 days: not listed
        product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
        product($this->shop, $this->location, 'Never', stock: 10, perDay: 0);    // no sales: never an action
        forecastAll($this->shop);

        $data = $this->getJson('/api/dashboard', $this->auth)->assertOk()->json('data');

        expect(array_column($data['actions']['out_of_stock'], 'name'))->toBe(['Empty'])
            ->and(array_column($data['actions']['order_today'], 'name'))->toBe(['Mug'])
            ->and(array_column($data['actions']['this_week'], 'name'))->toBe(['Soon'])
            ->and(array_column($data['runway'], 'name'))->toBe(['Empty', 'Mug', 'Soon', 'Later']);
    });

    it('refreshes its cache as soon as forecasts change', function () {
        $mug = product($this->shop, $this->location, 'Mug', stock: 500, perDay: 4);
        forecastAll($this->shop);
        $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.counts.reorder_now', 0);

        InventoryLevel::where('variant_id', $mug->id)->update(['available' => 5]);
        forecastAll($this->shop);

        $this->getJson('/api/dashboard', $this->auth)->assertJsonPath('data.counts.reorder_now', 1);
    });
});

describe('SKU list and detail', function () {
    beforeEach(function () {
        $this->mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4, attrs: ['sku' => 'MUG-1']);
        $this->plate = product($this->shop, $this->location, 'Plate', stock: 500, perDay: 4);
        $this->vase = product($this->shop, $this->location, 'Vase', stock: 30, perDay: 0);
        forecastAll($this->shop);
    });

    it('lists forecasts most urgent first with a status', function () {
        $this->getJson('/api/forecasts', $this->auth)
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Mug')
            ->assertJsonPath('data.0.status', 'reorder_now')
            ->assertJsonPath('data.0.suggested_qty', 194);
    });

    it('computes the status badge in the shop timezone', function () {
        // 23:30 UTC on Sep 20 is already Sep 21 in Tokyo: a reorder date of Sep 21 is "now" there.
        $this->shop->update(['timezone' => 'Asia/Tokyo', 'access_token_expires_at' => '2026-09-21 12:00:00']);
        $this->travelTo('2026-09-20 23:30:00');
        Forecast::where('variant_id', $this->plate->id)->update(['reorder_date' => '2026-09-21']);

        $this->getJson('/api/forecasts?search=Plate', $this->auth)->assertJsonPath('data.0.status', 'reorder_now');
    });

    it('filters by status and searches by title or SKU', function () {
        $this->getJson('/api/forecasts?status=slow', $this->auth)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Vase');
        $this->getJson('/api/forecasts?search=mug-1', $this->auth)->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'MUG-1');
        $this->getJson('/api/forecasts?status=bogus', $this->auth)->assertUnprocessable();
    });

    it('returns the detail with explanation lines, overrides and settings', function () {
        $this->getJson("/api/forecasts/{$this->mug->id}", $this->auth)
            ->assertOk()
            ->assertJsonPath('data.explanation_lines.0', ['code' => 'sells_over_window', 'params' => ['avg' => 4, 'count' => 30]])
            ->assertJsonPath('data.explanation.lead_time.source', 'shop_default')
            ->assertJsonPath('data.defaults.lead_time_days', 14)
            ->assertJsonPath('data.settings.supplier_id', null);
    });

    it('applies an override immediately and removes it again', function () {
        $this->putJson("/api/forecasts/{$this->mug->id}/overrides", [
            'avg_daily_sales' => ['value' => 10, 'note' => 'TikTok promo', 'expires_at' => '2026-10-31'],
        ], $this->auth)
            ->assertOk()
            ->assertJsonPath('data.avg_daily_sales', 10)
            ->assertJsonPath('data.overrides.avg_daily_sales.note', 'TikTok promo')
            ->assertJsonPath('data.explanation.avg_source', 'override');

        $this->putJson("/api/forecasts/{$this->mug->id}/overrides", ['avg_daily_sales' => ['value' => null]], $this->auth)
            ->assertOk()
            ->assertJsonPath('data.avg_daily_sales', 4);

        expect(ForecastOverride::count())->toBe(0);
    });

    it('recomputes a SKU right away when its settings change', function () {
        $supplier = Supplier::factory()->for($this->shop)->create(['name' => 'Acme', 'lead_time_days' => 30]);

        $this->putJson("/api/variants/{$this->mug->id}/settings", ['supplier_id' => $supplier->id, 'safety_days' => 0], $this->auth)->assertOk();

        $f = Forecast::where('variant_id', $this->mug->id)->first();
        expect($f->reorder_point)->toBe(120) // 4 x 30
            ->and($f->explanation['lead_time']['supplier'])->toBe('Acme');
    });

    it('queues a recompute for bulk settings changes', function () {
        $this->putJson('/api/variants/settings', ['variant_ids' => [$this->mug->id, $this->plate->id], 'lead_time_override' => 7], $this->auth)
            ->assertOk()->assertJsonPath('data.updated', 2);

        Queue::assertPushed(RecomputeForecasts::class, fn ($j) => $j->variantIds === [$this->mug->id, $this->plate->id]);
    });

    it('accepts Shopify variant ids from the resource picker in bulk updates', function () {
        $this->mug->update(['shopify_variant_id' => 555]);
        $supplier = Supplier::factory()->for($this->shop)->create();

        $this->putJson('/api/variants/settings', ['variant_ids' => ['gid://shopify/ProductVariant/555'], 'supplier_id' => $supplier->id], $this->auth)
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->putJson('/api/variants/settings', ['variant_ids' => ['DROP TABLE'], 'supplier_id' => $supplier->id], $this->auth)
            ->assertUnprocessable();

        expect($this->mug->fresh()->supplier_id)->toBe($supplier->id);
    });

    it('never exposes or edits another shop\'s data', function () {
        $other = Shop::factory()->create();
        $foreign = product($other, Location::factory()->for($other)->create(), 'Secret', stock: 1, perDay: 1);
        $foreignSupplier = Supplier::factory()->for($other)->create();
        forecastAll($other);

        $this->getJson("/api/forecasts/{$foreign->id}", $this->auth)->assertNotFound();
        $this->putJson("/api/forecasts/{$foreign->id}/overrides", ['avg_daily_sales' => ['value' => 1]], $this->auth)->assertNotFound();
        $this->putJson("/api/variants/{$foreign->id}/settings", ['safety_days' => 1], $this->auth)->assertNotFound();
        $this->putJson("/api/variants/{$this->mug->id}/settings", ['supplier_id' => $foreignSupplier->id], $this->auth)->assertUnprocessable();
        $this->putJson("/api/suppliers/{$foreignSupplier->id}", ['name' => 'x'], $this->auth)->assertNotFound();
        $this->getJson('/api/forecasts?search=Secret', $this->auth)->assertJsonCount(0, 'data');
    });
});

describe('settings, suppliers and bundles', function () {
    it('reads and updates store defaults and alert preferences', function () {
        $this->putJson('/api/settings', [
            'default_lead_time_days' => 20,
            'alerts' => ['email' => 'a@b.test', 'enabled' => true, 'frequency' => 'weekly', 'weekly_day' => 1],
        ], $this->auth)
            ->assertOk()
            ->assertJsonPath('data.default_lead_time_days', 20)
            ->assertJsonPath('data.alerts.frequency', 'weekly');

        Queue::assertPushed(RecomputeForecasts::class);
        $this->getJson('/api/settings', $this->auth)->assertJsonPath('data.alerts.email', 'a@b.test');
    });

    it('manages suppliers', function () {
        $id = $this->postJson('/api/suppliers', ['name' => 'Acme', 'lead_time_days' => 21], $this->auth)
            ->assertCreated()->json('data.id');

        $this->postJson('/api/suppliers', ['name' => 'Acme'], $this->auth)->assertUnprocessable(); // unique per shop
        $this->putJson("/api/suppliers/{$id}", ['name' => 'Acme Ltd', 'lead_time_days' => 30], $this->auth)->assertOk();
        $this->getJson('/api/suppliers', $this->auth)->assertJsonPath('data.0.name', 'Acme Ltd')->assertJsonPath('data.0.lead_time_days', 30);
        $this->deleteJson("/api/suppliers/{$id}", [], $this->auth)->assertNoContent();
        $this->getJson('/api/suppliers', $this->auth)->assertJsonCount(0, 'data');
    });

    it('creates a manual bundle from Shopify variant ids (resource picker) and deletes it', function () {
        $box = Variant::factory()->for($this->shop)->create(['shopify_variant_id' => 900, 'product_title' => 'Gift box', 'title' => 'Default Title']);
        $mug = Variant::factory()->for($this->shop)->create(['shopify_variant_id' => 101, 'product_title' => 'Mug']);

        $this->postJson('/api/bundles', [
            'bundle' => 'gid://shopify/ProductVariant/900',
            'components' => [['variant' => 'gid://shopify/ProductVariant/101', 'quantity' => 3]],
        ], $this->auth)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Gift box')
            ->assertJsonPath('data.components.0.quantity', 3)
            ->assertJsonPath('data.editable', true);

        expect($box->fresh()->is_bundle)->toBeTrue();
        Queue::assertPushed(RecomputeForecasts::class);

        $this->deleteJson("/api/bundles/{$box->id}", [], $this->auth)->assertNoContent();
        expect($box->fresh()->is_bundle)->toBeFalse()->and(BundleComponent::count())->toBe(0);
    });

    it('rejects bundles containing themselves or unknown products', function () {
        $box = Variant::factory()->for($this->shop)->create();

        $this->postJson('/api/bundles', ['bundle' => $box->id, 'components' => [['variant' => $box->id, 'quantity' => 1]]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors('components.0.variant');
        $this->postJson('/api/bundles', ['bundle' => 'gid://shopify/ProductVariant/404', 'components' => [['variant' => $box->id, 'quantity' => 1]]], $this->auth)
            ->assertUnprocessable()->assertJsonValidationErrors('bundle');
    });

    it('marks native Shopify bundles as read-only', function () {
        $box = Variant::factory()->for($this->shop)->bundle()->create();
        $part = Variant::factory()->for($this->shop)->create();
        BundleComponent::create(['shop_id' => $this->shop->id, 'bundle_variant_id' => $box->id, 'component_variant_id' => $part->id, 'quantity' => 2, 'source' => 'shopify']);

        $this->getJson('/api/bundles', $this->auth)
            ->assertJsonPath('data.0.editable', false)
            ->assertJsonPath('data.0.components.0.source', 'shopify');
    });

    it('searches variants for pickers', function () {
        Variant::factory()->for($this->shop)->create(['product_title' => 'Blue mug', 'sku' => 'BM']);

        $this->getJson('/api/variants?search=blue', $this->auth)->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BM');
    });
});

describe('stock on the way', function () {
    it('counts Shopify incoming stock in the suggestion and explains it', function () {
        $mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
        InventoryLevel::where('variant_id', $mug->id)->update(['incoming' => 60]);
        forecastAll($this->shop);

        $this->getJson("/api/forecasts/{$mug->id}", $this->auth)
            ->assertOk()
            ->assertJsonPath('data.current_stock', 10)
            ->assertJsonPath('data.incoming_stock', 60)
            ->assertJsonPath('data.suggested_qty', 134) // 4 x (14 + 7 + 30) - (10 + 60)
            ->assertJsonPath('data.explanation_lines.2', ['code' => 'incoming_stock', 'params' => ['count' => 60]]);
    });
});

describe('order rounding', function () {
    it('saves minimum order and pack size and recomputes the suggestion at once', function () {
        $mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
        forecastAll($this->shop);

        $this->putJson("/api/variants/{$mug->id}/settings", ['min_order_qty' => 200, 'pack_size' => 24], $this->auth)->assertOk();

        $this->getJson("/api/forecasts/{$mug->id}", $this->auth)
            ->assertJsonPath('data.settings.min_order_qty', 200)
            ->assertJsonPath('data.settings.pack_size', 24)
            ->assertJsonPath('data.suggested_qty', 216) // needs 194 -> minimum 200 -> 9 packs of 24
            ->assertJsonPath('data.explanation.reorder.rounding', ['needed' => 194, 'min_order_qty' => 200, 'pack_size' => 24, 'final' => 216]);

        $this->putJson("/api/variants/{$mug->id}/settings", ['pack_size' => 0], $this->auth)
            ->assertUnprocessable()->assertJsonPath('errors.pack_size.0', ['code' => 'min', 'params' => ['value' => 1]]);
    });
});

describe('manual min / max', function () {
    it('saves min and max, and rejects a max below the min', function () {
        $mug = product($this->shop, $this->location, 'Mug', stock: 10, perDay: 4);
        forecastAll($this->shop);

        $this->putJson("/api/variants/{$mug->id}/settings", ['min_stock' => 50, 'max_stock' => 400], $this->auth)->assertOk();
        $this->getJson("/api/forecasts/{$mug->id}", $this->auth)
            ->assertJsonPath('data.settings.min_stock', 50)
            ->assertJsonPath('data.settings.max_stock', 400)
            ->assertJsonPath('data.reorder_point', 50)
            ->assertJsonPath('data.suggested_qty', 390)
            ->assertJsonPath('data.explanation_lines.1', ['code' => 'reorder_point_manual', 'params' => ['count' => 50]]);

        // Only max sent: checked against the saved min.
        $this->putJson("/api/variants/{$mug->id}/settings", ['max_stock' => 20], $this->auth)
            ->assertUnprocessable()->assertJsonPath('errors.max_stock.0.code', 'max_below_min');
    });
});
