<?php

namespace App\Repositories\Eloquent;

use App\Models\Shop;
use App\Repositories\Contracts\FlowRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class EloquentFlowRepository implements FlowRepositoryInterface
{
    private const CHUNK = 500;

    public function recordLifecycle(Shop $shop, string $definitionId, bool $enabled, CarbonInterface $at): void
    {
        $key = ['shop_id' => $shop->id, 'definition_id' => mb_substr($definitionId, 0, 255)];
        $at = $at->utc()->format('Y-m-d H:i:s.v');

        DB::transaction(function () use ($key, $enabled, $at) {
            $existing = DB::table('flow_subscriptions')->where($key)->lockForUpdate()->first();
            if ($existing === null) {
                DB::table('flow_subscriptions')->insert($key + ['enabled' => $enabled, 'event_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ((string) $existing->event_at <= $at) {
                DB::table('flow_subscriptions')->where('id', $existing->id)->update(['enabled' => $enabled, 'event_at' => $at, 'updated_at' => now()]);
            }
        });
    }

    public function hasEnabledFlow(Shop $shop): bool
    {
        return DB::table('flow_subscriptions')->where('shop_id', $shop->id)->where('enabled', true)->exists();
    }

    public function states(Shop $shop, string $subject): array
    {
        return DB::table('flow_trigger_states')->where('shop_id', $shop->id)->where('subject', $subject)
            ->pluck('state', 'subject_id')
            ->mapWithKeys(fn ($state, $id) => [(int) $id => json_decode((string) $state, true) ?? []])->all();
    }

    public function saveStates(Shop $shop, string $subject, array $states): void
    {
        $now = now();
        $rows = [];
        foreach ($states as $id => $state) {
            $rows[] = ['shop_id' => $shop->id, 'subject' => $subject, 'subject_id' => $id, 'state' => json_encode($state, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('flow_trigger_states')->upsert($chunk, ['shop_id', 'subject', 'subject_id'], ['state', 'updated_at']);
        }
    }

    public function pruneStates(Shop $shop, string $subject, array $keepIds): void
    {
        $keep = array_flip($keepIds);
        $stale = DB::table('flow_trigger_states')->where('shop_id', $shop->id)->where('subject', $subject)
            ->pluck('subject_id')->reject(fn ($id) => isset($keep[(int) $id]))->values()->all();
        foreach (array_chunk($stale, self::CHUNK) as $ids) {
            DB::table('flow_trigger_states')->where('shop_id', $shop->id)->where('subject', $subject)->whereIn('subject_id', $ids)->delete();
        }
    }
}
