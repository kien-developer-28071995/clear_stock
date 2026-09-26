<?php

namespace App\Services\App;

use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyReauthorizeException;
use App\Jobs\Forecast\RecomputeForecasts;
use App\Models\Shop;
use App\Repositories\Contracts\AlertSettingRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\Shopify\AdminApiClient;
use App\Support\CacheKeys;
use App\Support\Monitor;
use Illuminate\Contracts\Cache\Repository as Cache;

/** The two onboarding questions: default lead time (pre-filled 14) and the alert email. */
class OnboardingService
{
    public function __construct(
        private readonly ShopRepositoryInterface $shops,
        private readonly AlertSettingRepositoryInterface $alerts,
        private readonly AdminApiClient $admin,
        private readonly Cache $cache,
    ) {}

    /** @return array{onboarded: bool, lead_time_days: int, alert_email: ?string} */
    public function state(Shop $shop): array
    {
        return [
            'onboarded' => $shop->onboarded_at !== null,
            'lead_time_days' => $shop->default_lead_time_days,
            'alert_email' => $this->alerts->forShop($shop)?->email ?? $this->contactEmail($shop),
        ];
    }

    public function complete(Shop $shop, int $leadTimeDays, ?string $alertEmail): Shop
    {
        $leadChanged = $shop->default_lead_time_days !== $leadTimeDays;

        $shop = $this->shops->update($shop, [
            'default_lead_time_days' => $leadTimeDays,
            'onboarded_at' => $shop->onboarded_at ?? now(),
        ]);
        $this->alerts->upsert($shop, ['email' => $alertEmail, 'enabled' => $alertEmail !== null]);

        if ($leadChanged) {
            RecomputeForecasts::dispatch($shop->id);
        }

        return $shop;
    }

    /** The store's contact email, only to pre-fill the form (cached, never required). */
    private function contactEmail(Shop $shop): ?string
    {
        return $this->cache->remember(CacheKeys::shopContactEmail($shop->id), 86400, function () use ($shop) {
            try {
                return $this->admin->query($shop, '{ shop { contactEmail } }')['shop']['contactEmail'] ?? null;
            } catch (ShopifyApiException|ShopifyReauthorizeException $e) {
                Monitor::expected($e, 'onboarding contact email', ['shop' => $shop->domain]);

                return null;
            }
        });
    }
}
