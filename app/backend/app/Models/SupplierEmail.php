<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Observers\SupplierEmailObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A purchase order emailed to a supplier (by the merchant, or automatically).
 *
 * @property int $id
 * @property int $shop_id
 * @property int $supplier_id
 * @property string $trigger manual | auto
 * @property string $to_email
 * @property ?string $reply_to
 * @property array<int, array{variant_id: int, sku: ?string, name: string, quantity: int}> $items
 * @property int $total_units
 * @property Carbon $created_at
 */
#[ObservedBy(SupplierEmailObserver::class)]
class SupplierEmail extends Model
{
    use BelongsToShop;

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTO = 'auto';

    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'supplier_id', 'trigger', 'to_email', 'reply_to', 'items', 'total_units'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total_units' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
