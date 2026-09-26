<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use Carbon\CarbonInterface;

/** Shopify Flow: which shops use our triggers, and what each trigger last saw. */
interface FlowRepositoryInterface
{
    /** Record a lifecycle callback; ignored when an entry with a newer timestamp exists. */
    public function recordLifecycle(Shop $shop, string $definitionId, bool $enabled, CarbonInterface $at): void;

    /** Whether any workflow of the shop uses one of our triggers. */
    public function hasEnabledFlow(Shop $shop): bool;

    /** @return array<int, array<string, mixed>> subject id => state */
    public function states(Shop $shop, string $subject): array;

    /** @param array<int, array<string, mixed>> $states subject id => state */
    public function saveStates(Shop $shop, string $subject, array $states): void;

    /** @param array<int, int> $keepIds drop the state of every other subject (deleted products, suppliers) */
    public function pruneStates(Shop $shop, string $subject, array $keepIds): void;
}
