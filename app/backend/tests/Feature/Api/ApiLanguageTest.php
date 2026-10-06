<?php

use App\Models\Location;
use App\Models\Shop;
use App\Models\Variant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// The API never returns text: errors and explanations are snake_case codes (with params)
// that the app translates. The only language setting the backend knows is the one saved
// in Settings.

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/graphql.json' => Http::response(['data' => ['shop' => ['contactEmail' => 'owner@demo.test']]])]);
    $this->travelTo('2026-09-20 10:00:00');
    $this->shop = Shop::factory()->create(['domain' => 'demo.myshopify.com', 'name' => 'Demo Store', 'timezone' => 'UTC', 'currency' => 'USD', 'plan' => 'growth']);
    $this->location = Location::factory()->for($this->shop)->create();
    $this->auth = ['Authorization' => 'Bearer '.sessionToken()];
});

describe('error codes', function () {
    it('returns validation errors as codes with params', function () {
        $this->postJson('/api/onboarding', ['lead_time_days' => 0, 'alert_email' => 'nope'], $this->auth)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.lead_time_days.0', ['code' => 'min', 'params' => ['value' => 1]])
            ->assertJsonPath('errors.alert_email.0.code', 'email')
            ->assertJsonMissingPath('message');
    });

    it('returns business-rule validation errors as codes', function () {
        $box = Variant::factory()->for($this->shop)->create();

        $this->postJson('/api/bundles', ['bundle' => $box->id, 'components' => [['variant' => $box->id, 'quantity' => 1]]], $this->auth)
            ->assertJsonPath('errors', ['components.0.variant' => [['code' => 'bundle_contains_itself', 'params' => []]]]);
        $this->putJson('/api/variants/settings', ['variant_ids' => ['nope'], 'safety_days' => 3], $this->auth)
            ->assertJsonPath('errors', ['variant_ids.0' => [['code' => 'invalid_product', 'params' => []]]]);
    });

    it('returns codes for missing records, unknown routes and expired sessions', function () {
        $this->getJson('/api/forecasts/999999', $this->auth)->assertNotFound()->assertExactJson(['code' => 'forecast_not_found', 'params' => []]);
        $this->getJson('/api/nope', $this->auth)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/shop', ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized()
            ->assertExactJson(['code' => 'session_expired', 'params' => []]);
    });
});

describe('language', function () {
    it('saves the language chosen in Settings (null = Shopify admin language)', function () {
        $this->putJson('/api/settings', ['locale' => 'vi'], $this->auth)->assertOk()->assertJsonPath('data.locale', 'vi');
        $this->getJson('/api/shop', $this->auth)->assertJsonPath('data.locale', 'vi');

        foreach (['es', 'de', 'fr', 'pt'] as $locale) {
            $this->putJson('/api/settings', ['locale' => $locale], $this->auth)->assertOk()->assertJsonPath('data.locale', $locale);
        }

        $this->putJson('/api/settings', ['locale' => 'xx'], $this->auth)
            ->assertUnprocessable()->assertJsonPath('errors.locale.0.code', 'in');

        $this->putJson('/api/settings', ['locale' => null], $this->auth)->assertOk()->assertJsonPath('data.locale', null);
    });
});
