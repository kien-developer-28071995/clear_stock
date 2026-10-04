<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shop that installed the app at some point (the report's own ledger).
 *
 * @property int $id
 * @property int $app_shop_id
 * @property ?string $domain null once the app has deleted the shop's data
 * @property ?string $name
 * @property string $plan
 * @property ?string $plan_interval
 * @property ?Carbon $first_installed_at
 * @property ?Carbon $installed_at
 * @property ?Carbon $uninstalled_at
 * @property ?Carbon $onboarded_at
 * @property ?Carbon $trial_started_at
 * @property ?string $plan_at_uninstall
 * @property ?Carbon $redacted_at
 */
class ShopRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_installed_at' => 'datetime', 'installed_at' => 'datetime', 'uninstalled_at' => 'datetime',
            'onboarded_at' => 'datetime', 'trial_started_at' => 'datetime', 'redacted_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShopEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function isInstalled(): bool
    {
        return $this->uninstalled_at === null;
    }

    /** installed | uninstalled | deleted (uninstalled and its data removed from the app) */
    public function status(): string
    {
        return match (true) {
            $this->redacted_at !== null => 'deleted',
            $this->uninstalled_at !== null => 'uninstalled',
            default => 'installed',
        };
    }

    public function label(): string
    {
        return $this->name ?: ($this->domain ?: "Shop #{$this->app_shop_id} (data deleted)");
    }

    /** Days between the latest install and the uninstall (or now). */
    public function daysInstalled(): ?int
    {
        return $this->installed_at === null ? null : (int) $this->installed_at->diffInDays($this->uninstalled_at ?? now());
    }
}
