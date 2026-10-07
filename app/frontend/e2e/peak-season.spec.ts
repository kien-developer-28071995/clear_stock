import { api, expect, php, settled, test } from './support/app';

/**
 * The Black Friday weekend suggested from last year's sales. A shop of its own with exactly the
 * history the suggestion needs (the dev store's history is not ours to shape), removed afterwards.
 * The suggestion only exists in the three months before the weekend: skipped outside them.
 */
const DOMAIN = 'e2e-peak.myshopify.com';
const remove = () => php(`$s = App\\Models\\Shop::firstWhere("domain", "${DOMAIN}"); if ($s) { app(App\\Repositories\\Contracts\\ShopRepositoryInterface::class)->purge($s); } echo json_encode(true);`);

test.use({ shopDomain: DOMAIN });
test.skip(!!process.env.E2E_FEATURES_OFF, 'sales events are switched off in this run');

test.beforeAll(() => {
    remove();
    // 4 units a day through the autumn of last year, 12 a day on its Black Friday weekend.
    php(`$shop = App\\Models\\Shop::factory()->create(["domain" => "${DOMAIN}", "name" => "Peak", "plan" => "free", "currency" => "USD", "timezone" => "UTC", "onboarded_at" => now(), "installed_at" => now()->subYears(2), "access_token_expires_at" => now()->addYear(), "sync_status" => "completed", "last_synced_at" => now()]);
        $variant = App\\Models\\Variant::factory()->for($shop)->create();
        [$from, $to] = App\\Services\\App\\PeakSeasonAdvisor::blackFridayWeekend(now()->year - 1);
        $rows = [];
        for ($day = $from->subDays(60); $day <= $to; $day = $day->addDay()) {
            $rows[] = ["shop_id" => $shop->id, "variant_id" => $variant->id, "date" => $day->toDateString(), "units_sold" => $day >= $from ? 12 : 4, "units_returned" => 0, "end_of_day_stock" => null, "was_in_stock" => true];
        }
        App\\Models\\DailySale::insert($rows); echo json_encode(true);`);
});
test.afterAll(() => remove());

test('the coming Black Friday weekend is suggested with last year\'s rise and added in one click', async ({ app }) => {
    await app.goto('/events');
    await settled(app);
    const suggestions = await api<{ data: { multiplier: number }[] }>(app, '/sales-events/suggestions');
    test.skip(suggestions.data.length === 0, 'more than three months before the Black Friday weekend');
    expect(suggestions.data[0].multiplier).toBe(3);

    const card = app.locator('s-section', { hasText: 'Black Friday – Cyber Monday is coming' });
    await expect(card).toBeVisible();
    await expect(card).toContainText('you sold 12 units a day, against 4 a day in the weeks before: +200%');
    await app.screenshot({ path: 'e2e-results/peak-season.png', fullPage: true });

    await card.locator('s-button', { hasText: 'Add event at +200%' }).click();
    // The event is in the list and there is nothing left to suggest.
    await expect(app.locator('s-table-row', { hasText: 'Black Friday – Cyber Monday' })).toContainText('+200%');
    await expect(card).toHaveCount(0);
    expect((await api<{ data: unknown[] }>(app, '/sales-events/suggestions')).data).toEqual([]);
});
