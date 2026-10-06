<?php

/** A production configuration that passes (v1: Free + Starter, Growth-only features off). */
function productionConfig(): void
{
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false,
        'app.url' => 'https://api.clearstock.example-host.net',
        'shopify.frontend_url' => 'https://app.clearstock.example-host.net',
        'shopify.api_key' => 'key', 'shopify.api_secret' => 'secret',
        'shopify.scopes' => 'read_products,read_inventory,read_locations,read_orders,read_all_orders',
        'shopify.support_email' => 'support@clearstock.app',
        'shopify.website_url' => 'https://clearstock.example-host.net',
        'billing.test' => false,
        'billing.plans.growth.offered' => false,
        'mail.default' => 'smtp', 'mail.from.address' => 'alerts@clearstock.app',
        'queue.default' => 'redis', 'cache.default' => 'redis',
        'monitoring.slack_webhook_url' => 'https://hooks.slack.test/x',
        'features.locations' => false, 'features.transfers' => false, 'features.realtime_alerts' => false,
        'features.flow_triggers' => false, 'features.supplier_emails' => false,
    ]);
}

it('passes a correct production configuration', function () {
    productionConfig();

    $this->artisan('app:preflight')->expectsOutputToContain('Preflight passed.')->assertSuccessful();
});

it('fails on anything that would break review or production', function (array $bad, string $message) {
    productionConfig();
    config($bad);

    $this->artisan('app:preflight')->expectsOutputToContain($message)->assertFailed();
})->with([
    'debug' => [['app.debug' => true], 'APP_DEBUG must be false'],
    'no frontend' => [['shopify.frontend_url' => null], 'FRONTEND_URL must be the https address'],
    'frontend on the API host' => [['shopify.frontend_url' => 'https://api.clearstock.example-host.net'], 'FRONTEND_URL must be its own host'],
    'test billing' => [['billing.test' => true], 'SHOPIFY_BILLING_TEST must be false'],
    'write_orders' => [['shopify.scopes' => 'read_products,write_orders'], 'contains write_orders'],
    'tunnel url' => [['app.url' => 'https://abc.trycloudflare.com'], 'quick tunnel'],
    'log mailer' => [['mail.default' => 'log'], 'MAIL_MAILER is log'],
    'example support' => [['shopify.support_email' => 'support@example.com'], 'SUPPORT_EMAIL'],
    'local website' => [['shopify.website_url' => 'http://localhost:4321'], 'WEBSITE_URL must be the https address'],
    'website is the app' => [['shopify.website_url' => 'https://api.clearstock.example-host.net'], 'WEBSITE_URL must not be APP_URL'],
    'growth with nothing' => [['billing.plans.growth.offered' => true], 'features:status reported a problem'],
]);
