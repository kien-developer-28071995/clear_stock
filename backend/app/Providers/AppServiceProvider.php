<?php

namespace App\Providers;

use App\Events\PlanChanged;
use App\Events\ShopInstalled;
use App\Events\ShopUninstalled;
use App\Listeners\LogEmails;
use App\Listeners\ReportLongQueueWait;
use App\Listeners\ReportShopEventsToSlack;
use App\Monitoring\ReportErrorsToSlack;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Forecast\ForecastCalculator;
use App\Services\ShopAuthService;
use App\Services\Shopify\AdminApiClient;
use App\Services\Shopify\SessionTokenValidator;
use App\Services\Shopify\ShopifyOAuthClient;
use App\Services\Shopify\ShopTokenService;
use App\Services\ShopService;
use App\Support\ShopContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The shop authenticated for the current request (set by VerifyShopifySessionToken).
        $this->app->scoped(ShopContext::class);

        $this->app->singleton(SessionTokenValidator::class, fn () => new SessionTokenValidator(
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
            (int) config('shopify.jwt_leeway'),
        ));

        $this->app->singleton(ShopifyOAuthClient::class, fn ($app) => new ShopifyOAuthClient(
            $app->make(Http::class),
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
            (int) config('shopify.http_timeout'),
        ));

        $this->app->singleton(ShopTokenService::class, fn ($app) => new ShopTokenService(
            $app->make(ShopifyOAuthClient::class),
            $app->make(ShopRepositoryInterface::class),
            $app->make(Cache::class),
            (int) config('shopify.token_refresh_margin'),
        ));

        $this->app->singleton(AdminApiClient::class, fn ($app) => new AdminApiClient(
            $app->make(Http::class),
            $app->make(ShopTokenService::class),
            (string) config('shopify.api_version'),
            (int) config('shopify.http_timeout'),
        ));

        $this->app->singleton(ForecastCalculator::class, fn () => ForecastCalculator::fromConfig());

        $this->app->singleton(ShopAuthService::class, fn ($app) => new ShopAuthService(
            $app->make(SessionTokenValidator::class),
            $app->make(ShopRepositoryInterface::class),
            $app->make(ShopTokenService::class),
            $app->make(ShopService::class),
            $app->make(Cache::class),
            (string) config('shopify.scopes'),
            (int) config('shopify.token_refresh_margin'),
        ));
    }

    public function boot(): void
    {
        // Error monitoring: every log record at error level (unhandled exceptions included) goes to Slack.
        Event::listen(MessageLogged::class, ReportErrorsToSlack::class);

        // Every email sent (or failed for good) is logged in email_logs, whatever sent it.
        Event::listen(MessageSent::class, [LogEmails::class, 'sent']);
        Event::listen(JobFailed::class, [LogEmails::class, 'failed']);

        // A queue waiting longer than its threshold (config/horizon.php "waits") reaches Slack.
        Event::listen(LongWaitDetected::class, ReportLongQueueWait::class);

        // Business events for the events Slack channel.
        Event::listen(ShopInstalled::class, [ReportShopEventsToSlack::class, 'installed']);
        Event::listen(ShopUninstalled::class, [ReportShopEventsToSlack::class, 'uninstalled']);
        Event::listen(PlanChanged::class, [ReportShopEventsToSlack::class, 'planChanged']);

        // Where an error happened, attached to its alert.
        Queue::before(fn (JobProcessing $e) => Context::add('job', $e->job->resolveName()));
        Queue::after(fn () => Context::forget('job'));
        Event::listen(CommandStarting::class, fn (CommandStarting $e) => $e->command && Context::add('command', $e->command));
    }
}
