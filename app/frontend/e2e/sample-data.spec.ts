import { expect, php, settled, test } from './support/app';

/**
 * Home before the shop has forecasts of its own: a made-up catalog shows what the app does.
 * A shop of its own is made for these tests (the dev store has forecasts) and removed afterwards.
 */
const DOMAIN = 'e2e-sample.myshopify.com';
const remove = () => php(`$s = App\\Models\\Shop::firstWhere("domain", "${DOMAIN}"); if ($s) { app(App\\Repositories\\Contracts\\ShopRepositoryInterface::class)->purge($s); } echo json_encode(true);`);
const state = (attributes: string) => php(`App\\Models\\Shop::firstWhere("domain", "${DOMAIN}")->forceFill(${attributes})->save(); Illuminate\\Support\\Facades\\Cache::flush(); echo json_encode(true);`);

test.use({ shopDomain: DOMAIN });
// Without the switch (make e2e-features-off) there is nothing to test here.
test.skip(!!process.env.E2E_FEATURES_OFF, 'sample data is switched off in this run');

test.beforeAll(() => {
    remove();
    php(`App\\Models\\Shop::factory()->create(["domain" => "${DOMAIN}", "name" => "Sample", "plan" => "free", "currency" => "USD", "timezone" => "UTC", "onboarded_at" => now(), "installed_at" => now(), "access_token_expires_at" => now()->addYear(), "sync_status" => "running", "last_synced_at" => null, "forecasted_at" => null]); echo json_encode(true);`);
});
test.afterAll(() => remove());

test('while the first import runs, Home forecasts a sample catalog and explains it', async ({ app }) => {
    state('["sync_status" => "running", "sync_error" => null, "last_synced_at" => null]');
    for (const width of [1280, 390]) {
        await app.setViewportSize({ width, height: 844 });
        await app.goto('/');
        await settled(app);
        await expect(app.locator('s-empty-state[heading="Preparing your forecast"]')).toBeVisible();
        await expect(app.getByText('Sample data', { exact: true })).toBeVisible();
        for (const name of ['Linen shirt - Sand / M', 'Canvas tote - Natural', 'Wool beanie - Grey', 'Ceramic mug - White', 'Scented candle - Cedar']) {
            await expect(app.getByText(name, { exact: true })).toBeVisible();
        }
        // The first product is explained without a click; the others on request, one at a time.
        await expect(app.getByText(/^Sells 3\.9\/day over the last 30 days/)).toBeVisible();
        await app.locator('s-button', { hasText: 'Why these numbers?' }).first().click();
        await expect(app.getByText(/^Sells 3\.9\/day over the last 30 days/)).toHaveCount(0);
        await expect(app.locator('s-button', { hasText: 'Hide' })).toHaveCount(1);
        // Never wider than the screen (3px: the test harness's own menu row).
        expect(await app.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(3);
        await app.screenshot({ path: `e2e-results/sample-data-${width}.png`, fullPage: true });
    }
});

test('a failed import shows its reason, not sample data', async ({ app }) => {
    state('["sync_status" => "failed", "sync_error" => ["code" => "unknown", "params" => []], "last_synced_at" => null]');
    await app.goto('/');
    await settled(app);
    await expect(app.locator('s-empty-state[heading="We couldn\'t import your store data"]')).toBeVisible();
    await expect(app.getByText('Sample data', { exact: true })).toHaveCount(0);
});
