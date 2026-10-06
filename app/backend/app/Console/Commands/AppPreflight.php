<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Production readiness check before an App Store submission or a deploy. Reads only the
 * configuration (no Shopify calls). Fails on anything that would break review or production.
 */
class AppPreflight extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Check the production configuration (App Store readiness)';

    /** Scopes the app may ask for; anything else needs a README justification first. */
    private const KNOWN_SCOPES = [
        'read_products', 'read_inventory', 'read_locations', 'read_orders', 'read_all_orders',
        'read_merchant_managed_fulfillment_orders', 'read_third_party_fulfillment_orders',
    ];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->check(app()->environment('production'), 'APP_ENV must be production.');
        $this->check(! config('app.debug'), 'APP_DEBUG must be false (it leaks stack traces).');
        $this->check(str_starts_with((string) config('app.url'), 'https://') && ! str_contains((string) config('app.url'), 'REPLACE'), 'APP_URL must be the https production host.');
        $this->check(! str_contains((string) config('app.url'), 'trycloudflare.com'), 'APP_URL is a quick tunnel, not a production host.');
        $this->check(filled(config('shopify.api_key')) && filled(config('shopify.api_secret')), 'SHOPIFY_API_KEY and SHOPIFY_API_SECRET are required (production app).');
        $this->check(! config('billing.test'), 'SHOPIFY_BILLING_TEST must be false (development stores get test charges automatically).');

        $scopes = array_filter(array_map('trim', explode(',', (string) config('shopify.scopes'))));
        $this->check(! in_array('write_orders', $scopes, true), 'SHOPIFY_SCOPES contains write_orders (dev only, for dev:fake-orders).');
        $unknown = array_diff($scopes, self::KNOWN_SCOPES);
        $this->check($unknown === [], 'Unexpected scopes: '.implode(', ', $unknown).' (each scope needs a justification in the README).');

        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER is {$mailer}: emails would never be delivered.");
        $this->check(! str_contains((string) config('mail.from.address'), 'example.com'), 'MAIL_FROM_ADDRESS is still an example address.');
        $this->check(! str_contains((string) config('shopify.support_email'), 'example.com'), 'SUPPORT_EMAIL is still an example address (shown on the website\'s /support and /privacy).');
        $website = (string) config('shopify.website_url');
        $this->check(
            str_starts_with($website, 'https://') && ! preg_match('/REPLACE|example\.com|localhost/', $website),
            'WEBSITE_URL must be the https address of the website (its /privacy and /support are the App Store listing URLs).',
        );
        $this->check(parse_url($website, PHP_URL_HOST) !== parse_url((string) config('app.url'), PHP_URL_HOST), 'WEBSITE_URL must not be APP_URL: the app redirects /privacy and /support to the website.');
        $this->check(config('queue.default') === 'redis', 'QUEUE_CONNECTION should be redis (Horizon).');
        $this->check(config('cache.default') === 'redis', 'CACHE_STORE should be redis.');

        $this->warnUnless(filled(config('monitoring.slack_webhook_url')), 'MONITORING_SLACK_WEBHOOK_URL is empty: errors will only be in the logs.');

        // Feature switches: combinations that cannot work fail here too.
        $featuresOk = Artisan::call('features:status') === self::SUCCESS;
        $this->line(Artisan::output());
        $this->check($featuresOk, 'features:status reported a problem (see above).');

        foreach ($this->warnings as $warning) {
            $this->warn("WARN  {$warning}");
        }
        foreach ($this->errors as $error) {
            $this->error("FAIL  {$error}");
        }
        if ($this->errors === []) {
            $this->info('Preflight passed.');
        }

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            $this->errors[] = $message;
        }
    }

    private function warnUnless(bool $ok, string $message): void
    {
        if (! $ok) {
            $this->warnings[] = $message;
        }
    }
}
