<?php

namespace App\Services\Flow;

use App\Enums\Feature;
use App\Models\Shop;
use App\Repositories\Contracts\FlowRepositoryInterface;
use App\Repositories\Contracts\ForecastQueryRepositoryInterface;
use App\Services\Shopify\FlowTriggerClient;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Growth: sends the forecast-based Shopify Flow triggers after forecasts change. Only to shops
 * with an active workflow using one of them (lifecycle callbacks), each trigger once per change.
 */
class FlowTriggerService
{
    /** Triggers sent per run; the rest keep their previous state and go out on the next run. */
    public const MAX_PER_RUN = 250;

    /** Supplier orders first: fewest and most useful when a run is capped. */
    private const PRIORITY = [FlowTriggerPlanner::SUPPLIER_REORDER => 0, FlowTriggerPlanner::PRODUCT_REORDER => 1, FlowTriggerPlanner::PRODUCT_STOCKOUT => 2];

    public function __construct(
        private readonly FlowRepositoryInterface $flow,
        private readonly ForecastQueryRepositoryInterface $forecasts,
        private readonly FlowTriggerClient $client,
        private readonly FlowTriggerPlanner $planner,
        private readonly FlowPayloads $payloads,
    ) {}

    public function shouldSend(Shop $shop): bool
    {
        return $shop->isInstalled() && Entitlements::for($shop)->has(Feature::FlowTriggers) && $this->flow->hasEnabledFlow($shop);
    }

    /** @return int triggers sent */
    public function send(Shop $shop, ?CarbonImmutable $now = null): int
    {
        if (! $this->shouldSend($shop)) {
            return 0;
        }

        $today = ($now ?? CarbonImmutable::now())->setTimezone($shop->timezone)->toDateString();
        $prevVariants = $this->flow->states($shop, 'variant');
        $prevSuppliers = $this->flow->states($shop, 'supplier');
        $plan = $this->planner->plan($this->forecasts->planningRows($shop, []), $prevVariants, $prevSuppliers, $today);

        $events = $plan['events'];
        usort($events, fn ($a, $b) => self::PRIORITY[$a['handle']] <=> self::PRIORITY[$b['handle']]);

        $sent = 0;
        try {
            foreach ($events as $i => $event) {
                if ($sent >= self::MAX_PER_RUN) {
                    break;
                }
                $this->client->trigger($shop, $event['handle'], $this->payload($shop, $event));
                $events[$i]['sent'] = true;
                $sent++;
            }
        } finally {
            // Whatever was not sent (cap, API error) keeps its old state and fires next time.
            [$variants, $suppliers] = $this->statesToSave($plan, $events, $prevVariants, $prevSuppliers);
            $this->flow->saveStates($shop, 'variant', $variants);
            $this->flow->saveStates($shop, 'supplier', $suppliers);
            $this->flow->pruneStates($shop, 'variant', array_keys($variants));
            $this->flow->pruneStates($shop, 'supplier', array_keys(array_filter($suppliers, fn ($s) => $s['due'] !== [])));

            if ($events !== []) {
                Log::info('Flow triggers sent', ['shop' => $shop->domain, 'sent' => $sent, 'due' => count($events)]);
            }
        }

        return $sent;
    }

    private function payload(Shop $shop, array $event): array
    {
        return match ($event['handle']) {
            FlowTriggerPlanner::PRODUCT_REORDER => $this->payloads->productReorder($shop, $event['row']),
            FlowTriggerPlanner::PRODUCT_STOCKOUT => $this->payloads->productStockout($shop, $event['row'], $event['level'], $event['days']),
            FlowTriggerPlanner::SUPPLIER_REORDER => $this->payloads->supplierReorder($shop, $event['rows'], $event['new']),
        };
    }

    /** @return array{0: array<int, array>, 1: array<int, array>} */
    private function statesToSave(array $plan, array $events, array $prevVariants, array $prevSuppliers): array
    {
        $variants = $plan['variant_states'];
        $suppliers = $plan['supplier_states'];

        foreach ($events as $event) {
            if ($event['sent'] ?? false) {
                continue;
            }
            $id = $event['subject_id'];
            match ($event['handle']) {
                FlowTriggerPlanner::PRODUCT_REORDER => $variants[$id]['reorder_due'] = $prevVariants[$id]['reorder_due'] ?? false,
                FlowTriggerPlanner::PRODUCT_STOCKOUT => $variants[$id]['stockout_level'] = $prevVariants[$id]['stockout_level'] ?? null,
                FlowTriggerPlanner::SUPPLIER_REORDER => $suppliers[$id] = $prevSuppliers[$id] ?? ['due' => []],
            };
        }

        return [$variants, $suppliers];
    }
}
