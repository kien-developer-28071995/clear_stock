<?php

namespace App\Models;

use App\Enums\BulkQueryKey;
use App\Enums\SyncRunStatus;
use App\Enums\SyncStage;
use App\Enums\SyncType;
use App\Models\Concerns\BelongsToShop;
use App\Observers\SyncRunObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property SyncType $type
 * @property SyncRunStatus $status
 * @property SyncStage $stage
 * @property int $progress
 * @property Carbon $window_start
 * @property ?Carbon $variants_updated_since
 * @property ?array<string, array{id: string, status: string, object_count: int, url: ?string, error_code: ?string}> $operations
 * @property ?array $stats
 * @property ?string $error
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 */
#[ObservedBy(SyncRunObserver::class)]
class SyncRun extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id', 'type', 'status', 'stage', 'progress', 'window_start', 'variants_updated_since',
        'operations', 'stats', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SyncType::class,
            'status' => SyncRunStatus::class,
            'stage' => SyncStage::class,
            'progress' => 'integer',
            'window_start' => 'date',
            'variants_updated_since' => 'datetime',
            'operations' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === SyncRunStatus::Running;
    }

    /** @return ?array{id: string, status: string, object_count: int, url: ?string, error_code: ?string} */
    public function operation(BulkQueryKey $key): ?array
    {
        return $this->operations[$key->value] ?? null;
    }
}
