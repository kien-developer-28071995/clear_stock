<?php

namespace App\Services\App;

use App\Enums\SyncStatus;
use App\Models\Shop;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Support\Features;

/**
 * Asking for an App Store review (every plan): Shopify's own review dialog, in its neutral words,
 * once per shop and only after the app has been useful: installed for a while, set up, syncing
 * fine. The embedded app opens the dialog at the end of a finished task (an order marked as
 * placed, a purchase order exported), never when the app opens. No reward, nothing held back
 * (App Store requirement 1.3.1). Shopify applies its own limits on top (60 days, 3 a year).
 */
class ReviewPromptService
{
    public const SWITCH = 'review_prompt';

    /** Shopify's answers after which asking again is pointless: the dialog was shown, or never will be. */
    public const FINAL = ['success', 'already-reviewed', 'merchant-ineligible'];

    /** Every answer of `shopify.reviews.request()`; the rest mean "not now" (cooldown, mobile, just installed). */
    public const CODES = [...self::FINAL, 'already-open', 'annual-limit-reached', 'cancelled', 'cooldown-period', 'mobile-app', 'open-in-progress', 'recently-installed'];

    public function __construct(private readonly ShopRepositoryInterface $shops) {}

    public function eligible(Shop $shop): bool
    {
        return Features::on(self::SWITCH)
            && $shop->review_prompted_at === null
            && $shop->onboarded_at !== null
            && $shop->installed_at !== null
            && $shop->installed_at->lte(now()->subDays((int) config('shopify.review_prompt_after_days')))
            && $shop->sync_status !== SyncStatus::Failed
            && $shop->sync_failure_notified_at === null
            && $shop->forecasted_at !== null;
    }

    /** What Shopify answered when the app asked for the dialog. */
    public function record(Shop $shop, string $code): void
    {
        if (in_array($code, self::FINAL, true) && $shop->review_prompted_at === null) {
            $this->shops->update($shop, ['review_prompted_at' => now(), 'review_prompt_result' => $code]);
        }
    }
}
