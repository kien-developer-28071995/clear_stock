<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $type installed | reinstalled | uninstalled | plan_changed | redacted
 * @property ?string $plan_from
 * @property ?string $plan_to
 * @property ?string $interval
 * @property string $mrr_change
 * @property Carbon $occurred_at
 */
class ShopEvent extends Model
{
    public const INSTALLED = 'installed';

    public const REINSTALLED = 'reinstalled';

    public const UNINSTALLED = 'uninstalled';

    public const PLAN_CHANGED = 'plan_changed';

    public const REDACTED = 'redacted';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'mrr_change' => 'decimal:2'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ShopRecord::class, 'shop_record_id');
    }
}
