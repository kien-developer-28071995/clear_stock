<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Observers\SupplierObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $shop_id
 * @property string $name
 * @property ?string $email
 * @property ?int $lead_time_days
 * @property ?int $min_order_qty default minimum order (units) for the supplier's products
 * @property ?int $pack_size default units per case for the supplier's products
 * @property ?int $order_cycle_days how often the merchant orders from this supplier (null = app default)
 * @property ?string $landed_cost_percent freight, duty and handling as a share of the supplier's price
 * @property ?string $min_order_value the supplier's minimum order value (shop currency)
 * @property ?array<int, int> $order_weekdays ISO weekdays orders are placed on (1 = Monday); null = any day
 */
#[ObservedBy(SupplierObserver::class)]
class Supplier extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'name', 'email', 'lead_time_days', 'min_order_qty', 'pack_size', 'order_cycle_days', 'order_weekdays', 'landed_cost_percent', 'min_order_value', 'auto_email'];

    protected function casts(): array
    {
        return ['lead_time_days' => 'integer', 'min_order_qty' => 'integer', 'pack_size' => 'integer', 'order_cycle_days' => 'integer', 'order_weekdays' => 'array', 'landed_cost_percent' => 'decimal:2', 'min_order_value' => 'decimal:2', 'auto_email' => 'boolean'];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /** @return HasMany<SupplierEmail, $this> */
    public function emails(): HasMany
    {
        return $this->hasMany(SupplierEmail::class);
    }
}
