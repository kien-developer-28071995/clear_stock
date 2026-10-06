<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $shopify_location_id
 * @property string $name
 * @property bool $is_active
 * @property bool $excluded stock here is not for sale (returns, damaged, showroom): not counted by forecasts
 */
class Location extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'shopify_location_id', 'name', 'is_active', 'excluded'];

    protected function casts(): array
    {
        return ['shopify_location_id' => 'integer', 'is_active' => 'boolean', 'excluded' => 'boolean'];
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }
}
