<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Uses of one feature by one shop on one day (see App\Support\FeatureUsage). Kept 400 days. */
class FeatureEvent extends Model
{
    use MassPrunable;

    public const RETENTION_DAYS = 400;

    public $timestamps = false;

    public function prunable(): Builder
    {
        return static::query()->where('day', '<', now('UTC')->subDays(self::RETENTION_DAYS)->toDateString());
    }
}
