<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A known change in sales over some days (a promotion, Black Friday, a closure), entered by the merchant.
 *
 * @property int $id
 * @property int $shop_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $multiplier sales x this on those days (2 = double, 0.5 = half)
 * @property string $applies_to all | supplier | products
 * @property ?int $supplier_id
 * @property ?array<int, int> $variant_ids
 */
class SalesEvent extends Model
{
    use BelongsToShop, HasFactory;

    public const ALL = 'all';

    public const SUPPLIER = 'supplier';

    public const PRODUCTS = 'products';

    protected $fillable = ['shop_id', 'name', 'starts_on', 'ends_on', 'multiplier', 'applies_to', 'supplier_id', 'variant_ids'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'multiplier' => 'decimal:2', 'variant_ids' => 'array', 'supplier_id' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function appliesTo(int $variantId, ?int $supplierId): bool
    {
        return match ($this->applies_to) {
            self::SUPPLIER => $supplierId !== null && $supplierId === $this->supplier_id,
            self::PRODUCTS => in_array($variantId, $this->variant_ids ?? [], true),
            default => true,
        };
    }

    /** @return array{name: string, from: string, to: string, multiplier: float} as the forecast uses it */
    public function toForecastEvent(): array
    {
        return ['name' => $this->name, 'from' => $this->starts_on->toDateString(), 'to' => $this->ends_on->toDateString(), 'multiplier' => (float) $this->multiplier];
    }
}
