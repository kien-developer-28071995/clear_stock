<?php

namespace App\Models;

use App\Enums\Plan;
use App\Enums\SyncStatus;
use App\Observers\ShopObserver;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
 * @property ?string $currency
 * @property string $timezone
 * @property int $default_lead_time_days
 * @property int $default_safety_days
 * @property ?Carbon $onboarded_at
 * @property SyncStatus $sync_status
 * @property ?string $sync_error
 * @property ?Carbon $last_synced_at
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
        'plan', 'currency', 'timezone', 'default_lead_time_days', 'default_safety_days', 'onboarded_at',
        'sync_status', 'sync_error', 'last_synced_at',
        'installed_at', 'uninstalled_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $attributes = [
        'plan' => 'free',
        'timezone' => 'UTC',
        'sync_status' => 'pending',
        'default_lead_time_days' => 14,
        'default_safety_days' => 7,
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'plan' => Plan::class,
            'sync_status' => SyncStatus::class,
            'last_synced_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'default_lead_time_days' => 'integer',
            'default_safety_days' => 'integer',
        ];
    }

    public function isInstalled(): bool
    {
        return $this->uninstalled_at === null && $this->access_token !== null;
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
