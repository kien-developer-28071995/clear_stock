<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\PlanInterval;
use App\Enums\SyncStatus;
use App\Observers\ShopObserver;
use Database\Factories\ShopFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $domain
 * @property ?string $name
 * @property ?string $access_token
 * @property ?Carbon $access_token_expires_at
 * @property ?string $refresh_token
 * @property ?Carbon $refresh_token_expires_at
 * @property ?string $scopes
 * @property Plan $plan
 * @property ?PlanInterval $plan_interval
 * @property ?string $subscription_id
 * @property ?string $subscription_status
 * @property ?Carbon $plan_renews_at
 * @property ?Carbon $trial_started_at
 * @property ?string $currency
 * @property string $timezone
 * @property ?string $locale Language chosen in Settings (null = Shopify admin language)
 * @property int $default_lead_time_days
 * @property int $default_safety_days
 * @property bool $filter_sales_spikes cap one-off sales spikes before averaging
 * @property string $forecast_profile default window mix (config forecast.profiles)
 * @property ?array<int, string> $excluded_order_tags orders with one of these tags are left out of the sales history
 * @property ?array<int, string> $excluded_order_sources order sources left out (pos, draft)
 * @property ?Carbon $onboarded_at
 * @property ?array{events?: array<string, string>, skipped?: array<int, string>, dismissed_at?: ?string, tips_dismissed?: array<int, string>} $setup_guide
 * @property SyncStatus $sync_status
 * @property ?array{code: string, params: array<string, mixed>} $sync_error
 * @property ?Carbon $sync_failure_notified_at merchant emailed about this streak of failed syncs
 * @property ?Carbon $last_synced_at
 * @property ?Carbon $forecasted_at
 * @property ?string $realtime_webhook_id shop-specific inventory_levels/update subscription (real-time alerts)
 * @property ?Carbon $review_prompted_at Shopify's review dialog was shown (or never will be): not asked again
 * @property ?Carbon $installed_at
 * @property ?Carbon $uninstalled_at
 */
#[ObservedBy(ShopObserver::class)]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    protected $fillable = [
        'domain', 'name',
        'access_token', 'access_token_expires_at', 'refresh_token', 'refresh_token_expires_at', 'scopes',
        'plan', 'plan_interval', 'subscription_id', 'subscription_status', 'plan_renews_at', 'trial_started_at', 'currency', 'timezone', 'locale', 'default_lead_time_days', 'default_safety_days', 'filter_sales_spikes', 'forecast_profile', 'excluded_order_tags', 'excluded_order_sources', 'order_budget', 'onboarded_at', 'setup_guide',
        'sync_status', 'sync_error', 'sync_failure_notified_at', 'last_synced_at', 'forecasted_at', 'realtime_webhook_id',
        'installed_at', 'uninstalled_at', 'review_prompted_at', 'review_prompt_result',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $attributes = [
        'plan' => 'free',
        'timezone' => 'UTC',
        'sync_status' => 'pending',
        'default_lead_time_days' => 14,
        'default_safety_days' => 7,
        'filter_sales_spikes' => true,
        'forecast_profile' => 'balanced',
    ];

    /**
     * Always a timezone PHP knows: Shopify's zone names are newer than a server's list now and
     * then, and one unknown name must not stop the hourly work for every shop. UTC until it is known.
     */
    protected function timezone(): Attribute
    {
        return Attribute::get(function (?string $value): string {
            static $known = [];

            return $known[$value ?? ''] ??= $value !== null && $value !== '' && @timezone_open($value) !== false ? $value : 'UTC';
        });
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'plan' => Plan::class,
            'plan_interval' => PlanInterval::class,
            'plan_renews_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'sync_status' => SyncStatus::class,
            'sync_error' => 'array',
            'sync_failure_notified_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'forecasted_at' => 'datetime',
            'installed_at' => 'datetime',
            'review_prompted_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'setup_guide' => 'array',
            'default_lead_time_days' => 'integer',
            'default_safety_days' => 'integer',
            'filter_sales_spikes' => 'boolean',
            'order_budget' => 'decimal:2',
            'excluded_order_tags' => 'array',
            'excluded_order_sources' => 'array',
        ];
    }

    public function isInstalled(): bool
    {
        // Raw column: no decryption needed to know a token is stored.
        return $this->uninstalled_at === null && $this->getRawOriginal('access_token') !== null;
    }

    /** False when the stored token can't be decrypted (APP_KEY changed without APP_PREVIOUS_KEYS). */
    public function hasReadableAccessToken(): bool
    {
        try {
            return $this->access_token !== null;
        } catch (DecryptException) {
            return false;
        }
    }

    /** Token is missing or (about to be) expired. Non-expiring tokens have no expiry date. */
    public function accessTokenNeedsRefresh(int $marginSeconds = 0): bool
    {
        if ($this->access_token === null) {
            return true;
        }

        return $this->access_token_expires_at !== null
            && $this->access_token_expires_at->subSeconds($marginSeconds)->isPast();
    }

    public function canRefreshToken(): bool
    {
        return $this->refresh_token !== null
            && ($this->refresh_token_expires_at === null || $this->refresh_token_expires_at->isFuture());
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function alertSetting(): HasOne
    {
        return $this->hasOne(AlertSetting::class);
    }
}
