<?php

namespace App\Models;

use App\Enums\OverrideField;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int $variant_id
 * @property OverrideField $field
 * @property string $value
 * @property ?string $note
 * @property ?Carbon $expires_at
 */
class ForecastOverride extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = ['shop_id', 'variant_id', 'field', 'value', 'note', 'expires_at'];

    protected function casts(): array
    {
        return [
            'field' => OverrideField::class,
            'value' => 'decimal:3',
            'expires_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
