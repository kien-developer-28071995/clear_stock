<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesWithBackoff;
use App\Mail\WelcomeMail;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\App\OnboardingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Welcome email after a first install, to the store's contact email (queued: looking the
 * address up is a Shopify call, which must not slow the install request).
 */
class SendWelcomeEmail implements ShouldQueue
{
    use Queueable, RetriesWithBackoff;

    public function __construct(public readonly int $shopId) {}

    public function handle(ShopRepositoryInterface $shops, OnboardingService $onboarding): void
    {
        $shop = $shops->findById($this->shopId);
        if (! $shop || ! $shop->isInstalled()) {
            return; // uninstalled before the job ran
        }

        $to = $onboarding->contactEmail($shop);
        if (! $to) {
            Log::info('No welcome email: the store has no contact email', ['shop' => $shop->domain]);

            return;
        }

        Mail::to($to)->queue(new WelcomeMail($shop));
    }
}
