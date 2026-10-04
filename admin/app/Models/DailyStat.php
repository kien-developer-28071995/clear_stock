<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Totals of one day, for the trend charts. */
class DailyStat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'plans' => 'array', 'features' => 'array', 'mrr' => 'decimal:2'];
    }
}
