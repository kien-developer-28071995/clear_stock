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
 */
#[ObservedBy(SupplierObserver::class)]
class Supplier extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'name', 'email', 'lead_time_days', 'min_order_qty', 'pack_size', 'auto_email'];

    protected function casts(): array
    {
        return ['lead_time_days' => 'integer', 'min_order_qty' => 'integer', 'pack_size' => 'integer', 'auto_email' => 'boolean'];
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
