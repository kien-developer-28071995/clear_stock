<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One web vital measured in the Shopify admin for the embedded app. Kept 35 days. */
class WebVital extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const METRICS = ['LCP', 'CLS', 'INP', 'FCP', 'TTFB'];

    public const RETENTION_DAYS = 35;

    protected $fillable = ['shop_id', 'metric', 'value', 'page'];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
