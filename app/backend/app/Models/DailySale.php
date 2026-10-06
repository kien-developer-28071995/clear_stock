<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $variant_id
 * @property Carbon $date
 * @property int $units_sold
 * @property int $units_returned
 * @property ?int $end_of_day_stock
 * @property bool $was_in_stock
 */
class DailySale extends Model
{
    use BelongsToShop, HasFactory;

    public $timestamps = false;

    /** Store `date` as a plain date so raw upserts (Y-m-d) and Eloquent writes hit the same unique key. */
    protected $dateFormat = 'Y-m-d';

    protected $fillable = ['shop_id', 'variant_id', 'date', 'units_sold', 'units_returned', 'end_of_day_stock', 'was_in_stock'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'units_sold' => 'integer',
            'units_returned' => 'integer',
            'end_of_day_stock' => 'integer',
            'was_in_stock' => 'boolean',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
