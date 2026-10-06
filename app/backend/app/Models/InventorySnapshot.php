<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stock on hand at the end of a forecast run, once a day (inventory value history).
 *
 * @property int $shop_id
 * @property Carbon $date
 * @property int $units
 * @property string $value units x unit cost, for products with a cost
 * @property int $products_in_stock
 * @property int $products_missing_cost
 */
class InventorySnapshot extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'date', 'units', 'value', 'products_in_stock', 'products_missing_cost'];

    protected function casts(): array
    {
        return ['date' => 'date', 'units' => 'integer', 'value' => 'decimal:2', 'products_in_stock' => 'integer', 'products_missing_cost' => 'integer'];
    }
}
