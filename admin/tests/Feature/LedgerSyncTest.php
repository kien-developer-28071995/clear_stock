<?php

namespace Tests\Feature;

use App\Models\DailyStat;
use App\Models\ShopEvent;
use App\Models\ShopRecord;
use App\Reports\LedgerSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AppSchema;
use Tests\TestCase;

class LedgerSyncTest extends TestCase
{
    use AppSchema, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppSchema();
        $this->travelTo('2026-10-01 08:00:00');
    }

    private function sync(): array
    {
        return app(LedgerSync::class)->run();
    }

    public function test_it_records_installs_and_shops_that_had_already_uninstalled(): void
    {
        $this->appShop(['domain' => 'a.myshopify.com', 'name' => 'A', 'plan' => 'starter', 'plan_interval' => 'monthly', 'installed_at' => '2026-09-01 10:00:00']);
        $this->appShop(['domain' => 'b.myshopify.com', 'installed_at' => '2026-09-10 10:00:00', 'uninstalled_at' => '2026-09-12 10:00:00']);

        $stats = $this->sync();

        $this->assertSame(['shops' => 2, 'installed' => 2, 'uninstalled' => 1, 'reinstalled' => 0, 'plan_changed' => 0, 'redacted' => 0], $stats);
        $a = ShopRecord::query()->where('domain', 'a.myshopify.com')->first();
        $this->assertSame('installed', $a->status());
        $this->assertSame('2026-09-01 10:00:00', $a->first_installed_at->toDateTimeString());
        $this->assertSame('4.00', $a->events->first()->mrr_change);
        $b = ShopRecord::query()->where('domain', 'b.myshopify.com')->first();
        $this->assertSame('uninstalled', $b->status());
        $this->assertSame(2, $b->daysInstalled());
        $this->assertSame(['uninstalled', 'installed'], $b->events->pluck('type')->all());

        // Running again changes nothing.
        $this->assertSame(0, $this->sync()['installed']);
        $this->assertSame(3, ShopEvent::query()->count());
    }

    public function test_it_records_plan_changes_uninstalls_and_reinstalls_with_the_mrr_they_moved(): void
    {
        $id = $this->appShop(['domain' => 'a.myshopify.com', 'installed_at' => '2026-09-01 10:00:00']);
        $this->sync();

        $this->updateAppShop($id, ['plan' => 'growth', 'plan_interval' => 'annual']);
        $this->assertSame(1, $this->sync()['plan_changed']);
        $event = ShopEvent::query()->where('type', 'plan_changed')->first();
        $this->assertSame(['free', 'growth', 'annual', '4.83'], [$event->plan_from, $event->plan_to, $event->interval, $event->mrr_change]);

        // The app resets the plan to Free when a shop uninstalls: the ledger remembers what it was on.
        $this->updateAppShop($id, ['plan' => 'free', 'plan_interval' => null, 'uninstalled_at' => '2026-09-30 12:00:00']);
        $stats = $this->sync();
        $this->assertSame([1, 0], [$stats['uninstalled'], $stats['plan_changed']]);
        $record = ShopRecord::query()->first();
        $this->assertSame('growth', $record->plan_at_uninstall);
        $left = ShopEvent::query()->where('type', 'uninstalled')->first();
        $this->assertSame(['growth', '-4.83', '2026-09-30 12:00:00'], [$left->plan_from, $left->mrr_change, $left->occurred_at->toDateTimeString()]);

        $this->updateAppShop($id, ['uninstalled_at' => null, 'installed_at' => '2026-10-01 07:00:00']);
        $this->assertSame(1, $this->sync()['reinstalled']);
        $record->refresh();
        $this->assertSame('installed', $record->status());
        $this->assertSame('2026-09-01 10:00:00', $record->first_installed_at->toDateTimeString());
        $this->assertSame('2026-10-01 07:00:00', $record->installed_at->toDateTimeString());
    }

    public function test_it_keeps_dates_but_no_name_or_domain_once_the_app_deleted_the_shop(): void
    {
        $id = $this->appShop(['domain' => 'gone.myshopify.com', 'name' => 'Gone', 'plan' => 'starter', 'plan_interval' => 'monthly', 'installed_at' => '2026-09-01 10:00:00']);
        $this->sync();
        $this->updateAppShop($id, ['uninstalled_at' => '2026-09-20 10:00:00', 'plan' => 'free']);
        $this->sync();

        DB::connection('app')->table('shops')->where('id', $id)->delete(); // shop/redact
        $this->assertSame(1, $this->sync()['redacted']);

        $record = ShopRecord::query()->first();
        $this->assertSame('deleted', $record->status());
        $this->assertNull($record->domain);
        $this->assertNull($record->name);
        $this->assertSame('starter', $record->plan_at_uninstall);
        $this->assertSame(19, $record->daysInstalled());
        $this->assertStringContainsString('data deleted', $record->label());
        $this->assertSame(0, $this->sync()['redacted']); // only once
    }

    public function test_a_shop_deleted_between_two_runs_still_counts_as_an_uninstall(): void
    {
        $id = $this->appShop(['plan' => 'starter', 'plan_interval' => 'monthly']);
        $this->sync();
        DB::connection('app')->table('shops')->where('id', $id)->delete();

        $stats = $this->sync();
        $this->assertSame([1, 1], [$stats['uninstalled'], $stats['redacted']]);
        $this->assertNotNull(ShopRecord::query()->first()->uninstalled_at);
    }

    public function test_it_stores_one_snapshot_of_totals_a_day(): void
    {
        $this->appShop(['plan' => 'starter', 'plan_interval' => 'monthly', 'forecasted_at' => '2026-09-30 20:00:00']);
        $this->appShop(['plan' => 'growth', 'plan_interval' => 'annual', 'forecasted_at' => '2026-09-01 20:00:00']);
        $this->appShop(['uninstalled_at' => '2026-09-12 10:00:00']);
        $supplierShop = $this->appShop();
        DB::connection('app')->table('suppliers')->insert(['shop_id' => $supplierShop, 'name' => 'Acme']);

        $this->sync();
        $this->sync();

        $this->assertSame(1, DailyStat::query()->count());
        $stat = DailyStat::query()->first();
        $this->assertSame('2026-10-01', $stat->date->toDateString());
        $this->assertSame([3, 2, 1, '8.83'], [$stat->installed, $stat->paying, $stat->active, $stat->mrr]);
        $this->assertSame(['starter' => 1, 'growth' => 1, 'free' => 1], $stat->plans);
        $this->assertSame(1, $stat->features['suppliers']);
        $this->assertArrayNotHasKey('weekly_summary', $stat->features); // the deployed app has no such column yet
    }
}
