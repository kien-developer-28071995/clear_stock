<?php

namespace App\Services\Sync;

use App\Mail\SyncFailingMail;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\SyncRunRepositoryInterface;
use App\Services\App\OnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the merchant, once, when syncing keeps failing: forecasts silently going stale is the
 * worst failure for a forecasting app. On every plan. One email per streak of failures; the
 * next successful sync clears the flag (SyncService). Nothing is sent on a single failure.
 */
class SyncFailureNotifier
{
    public function __construct(
        private readonly SyncRunRepositoryInterface $runs,
        private readonly ShopRepositoryInterface $shops,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly OnboardingService $onboarding,
    ) {}

    /** @return bool whether an email was queued */
    public function notifyIfFailing(Shop $shop, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        if (! $shop->isInstalled() || $shop->sync_failure_notified_at !== null) {
            return false;
        }

        $failures = $this->runs->consecutiveFailures($shop);
        $staleSince = $shop->last_synced_at ?? $shop->installed_at;
        $stale = $staleSince === null || $staleSince->lte($now->subHours((int) config('sync.failure_email.stale_hours')));
        if ($failures < (int) config('sync.failure_email.min_failures') || ! $stale) {
            return false;
        }

        $to = $this->alerts->forShop($shop)?->email ?: $this->onboarding->contactEmail($shop);
        if (! $to) {
            Log::warning('Sync failing, but no email address to tell the merchant', ['shop' => $shop->domain]);

            return false;
        }

        Mail::to($to)->queue(new SyncFailingMail($shop, $failures));
        $this->shops->update($shop, ['sync_failure_notified_at' => $now]);
        Log::info('Sync failure email queued', ['shop' => $shop->domain, 'failures' => $failures]);

        return true;
    }
}
