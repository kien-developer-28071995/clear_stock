<?php

namespace App\Console\Commands\Dev;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Models\Variant;
use App\Services\Shopify\AdminApiClient;
use App\Support\Dev\FakeSalesScenarios;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * DEV ONLY. Creates backdated test orders in a Shopify DEVELOPMENT store so the
 * whole pipeline (bulk sync -> daily_sales -> forecasts) can be tried with real data.
 *
 * - Needs the write_orders scope (dev only; remove before App Store submission).
 * - Development stores accept at most 5 new orders per minute, so orders are
 *   batched: one order per day for recent days, one per week further back.
 * - Orders are tagged "clear-stock-fake" and marked test; --purge deletes them.
 * - Inventory is not touched (inventoryBehaviour BYPASS).
 */
class FakeOrders extends Command
{
    public const TAG = 'clear-stock-fake';

    protected $signature = 'dev:fake-orders
        {--shop= : Shop domain (default: first installed shop)}
        {--daily-days=60 : One order per day for this many recent days}
        {--total-days=400 : History length; older days are grouped into one order per week}
        {--dry-run : Print the plan without creating orders}
        {--purge : Delete previously created fake orders instead}';

    protected $description = '[DEV] Create backdated fake orders in a development store';

    private const ORDER_CREATE = <<<'GQL'
        mutation FakeOrder($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
          orderCreate(order: $order, options: $options) {
            order { id name processedAt }
            userErrors { field message }
          }
        }
        GQL;

    public function handle(AdminApiClient $admin): int
    {
        if (! app()->environment('local')) {
            $this->error('This command only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $shop = $this->option('shop') ? Shop::firstWhere('domain', $this->option('shop')) : Shop::query()->whereNull('uninstalled_at')->first();
        if ($shop === null) {
            $this->error('Shop not found.');

            return self::FAILURE;
        }

        if (! $this->guard($admin, $shop)) {
            return self::FAILURE;
        }

        return $this->option('purge') ? $this->purge($admin, $shop) : $this->create($admin, $shop);
    }

    private function guard(AdminApiClient $admin, Shop $shop): bool
    {
        $data = $admin->query($shop, '{ shop { plan { partnerDevelopment } } currentAppInstallation { accessScopes { handle } } }');

        if (! ($data['shop']['plan']['partnerDevelopment'] ?? false)) {
            $this->error("{$shop->domain} is not a development store. Refusing to create fake orders.");

            return false;
        }

        $scopes = array_column($data['currentAppInstallation']['accessScopes'] ?? [], 'handle');
        if (! $this->option('dry-run') && ! in_array('write_orders', $scopes, true)) {
            $this->error('The app does not have write_orders on this store yet.');
            $this->line('  1. Add write_orders to scopes in shopify.app.toml and SHOPIFY_SCOPES in backend/.env');
            $this->line('  2. npx @shopify/cli@latest app deploy');
            $this->line('  3. Open the app in the Shopify admin and approve the new permission');

            return false;
        }

        return true;
    }

    private function create(AdminApiClient $admin, Shop $shop): int
    {
        $variants = Variant::query()->forShop($shop)->where('is_active', true)->where('tracked', true)->orderBy('id')->get();
        if ($variants->isEmpty()) {
            $this->error('No tracked variants. Run a sync first.');

            return self::FAILURE;
        }

        $scenarios = $this->assignScenarios($variants);
        $this->table(['Variant', 'Scenario', 'Pattern'], $variants->map(fn ($v) => [
            $v->displayName(), $scenarios[$v->id], FakeSalesScenarios::DESCRIPTIONS[$scenarios[$v->id]],
        ])->all());

        $orders = $this->plan($shop, $variants, $scenarios);
        $units = array_sum(array_map(fn ($o) => array_sum(array_column($o['lines'], 'quantity')), $orders));
        $minutes = (int) ceil(count($orders) / 5);
        $this->info(count($orders)." orders, {$units} units. Development stores allow 5 orders/minute: about {$minutes} min.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($orders));
        foreach ($orders as $order) {
            $this->createOrder($admin, $shop, $order);
            $bar->advance();
            usleep(12_500_000); // stay under 5 orders/minute
        }
        $bar->finish();
        $this->newLine(2);
        $this->info('Done. Now run a sync (Sync now in the app, or: php artisan tinker -> SyncService::start) to import them.');

        return self::SUCCESS;
    }

    /** @return array<int, string> variant id => scenario */
    private function assignScenarios($variants): array
    {
        $out = [];
        $used = [];
        foreach ($variants as $v) {
            foreach (FakeSalesScenarios::SCENARIOS as $needle => $scenario) {
                if (! isset($used[$scenario]) && str_contains($v->displayName(), $needle)) {
                    $out[$v->id] = $scenario;
                    $used[$scenario] = true;
                    break;
                }
            }
        }
        $i = 0;
        foreach ($variants as $v) {
            $out[$v->id] ??= FakeSalesScenarios::FALLBACK[$i++ % count(FakeSalesScenarios::FALLBACK)];
        }

        return $out;
    }

    /** @return array<int, array{processed_at: string, lines: array<int, array{variantId: string, quantity: int}>}> */
    private function plan(Shop $shop, $variants, array $scenarios): array
    {
        $today = CarbonImmutable::now($shop->timezone)->startOfDay();
        $daily = (int) $this->option('daily-days');
        $total = (int) $this->option('total-days');
        $orders = [];

        // Days beyond $daily are grouped by week (one order on the week's middle day); oldest first.
        $buckets = [];
        for ($ago = $daily + 1; $ago <= $total; $ago += 7) {
            $buckets[] = range($ago, min($total, $ago + 6));
        }
        $buckets = array_reverse($buckets);
        for ($ago = $daily; $ago >= 1; $ago--) {
            $buckets[] = [$ago];
        }

        foreach ($buckets as $bucket) {
            $lines = [];
            foreach ($variants as $v) {
                $qty = array_sum(array_map(fn ($d) => FakeSalesScenarios::units($scenarios[$v->id], $d), $bucket));
                if ($qty > 0) {
                    $lines[] = ['variantId' => "gid://shopify/ProductVariant/{$v->shopify_variant_id}", 'quantity' => $qty];
                }
            }
            if ($lines !== []) {
                $day = $today->subDays((int) round(array_sum($bucket) / count($bucket)));
                $orders[] = ['processed_at' => $day->setTime(12, 0)->toIso8601String(), 'lines' => $lines];
            }
        }

        return $orders;
    }

    private function createOrder(AdminApiClient $admin, Shop $shop, array $order): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $result = $admin->query($shop, self::ORDER_CREATE, [
                    'order' => [
                        'processedAt' => $order['processed_at'],
                        'lineItems' => $order['lines'],
                        'financialStatus' => 'PAID',
                        'test' => true,
                        'tags' => [self::TAG],
                    ],
                    'options' => ['inventoryBehaviour' => 'BYPASS', 'sendReceipt' => false, 'sendFulfillmentReceipt' => false],
                ])['orderCreate'];

                if (($result['userErrors'] ?? []) === []) {
                    return;
                }
                $message = $result['userErrors'][0]['message'];
            } catch (ShopifyApiException $e) {
                $message = $e->getMessage();
            }

            $this->newLine();
            $this->warn("Attempt {$attempt} for {$order['processed_at']}: {$message}");
            sleep(20 * $attempt); // order-rate limit or throttling: back off
        }

        $this->error("Giving up on the order for {$order['processed_at']}.");
    }

    private function purge(AdminApiClient $admin, Shop $shop): int
    {
        $deleted = 0;
        do {
            $ids = array_column($admin->query($shop, 'query($q: String!) { orders(first: 50, query: $q) { nodes { id } } }', ['q' => 'tag:'.self::TAG])['orders']['nodes'], 'id');
            foreach ($ids as $id) {
                $errors = $admin->query($shop, 'mutation($id: ID!) { orderDelete(orderId: $id) { userErrors { message } } }', ['id' => $id])['orderDelete']['userErrors'] ?? [];
                $errors === [] ? $deleted++ : $this->warn("{$id}: {$errors[0]['message']}");
            }
        } while ($ids !== []);

        $this->info("Deleted {$deleted} fake orders. Run a sync to refresh daily_sales.");

        return self::SUCCESS;
    }
}
