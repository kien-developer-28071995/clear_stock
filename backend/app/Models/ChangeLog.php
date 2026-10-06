<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One setting or forecast adjustment a merchant changed on a product. Kept 180 days. */
class ChangeLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 180;

    protected $fillable = ['shop_id', 'variant_id', 'field', 'old_value', 'new_value', 'source'];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
