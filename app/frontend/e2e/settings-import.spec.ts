import { expect, php, settled, test, toasts } from './support/app';

/**
 * Reorder settings of many products from a CSV: the file is shown as what it would change first,
 * and only "Apply" saves. A shop of its own (two products, one supplier), removed afterwards.
 */
const DOMAIN = 'e2e-import.myshopify.com';
const remove = () => php(`$s = App\\Models\\Shop::firstWhere("domain", "${DOMAIN}"); if ($s) { app(App\\Repositories\\Contracts\\ShopRepositoryInterface::class)->purge($s); } echo json_encode(true);`);
const mug = () => php<{ lead_time_override: number | null; pack_size: number | null }>(
    `echo json_encode(App\\Models\\Variant::where("sku", "E2E-MUG")->first()->only(["lead_time_override", "pack_size"]));`,
);

test.use({ shopDomain: DOMAIN });
test.skip(!!process.env.E2E_FEATURES_OFF, 'the settings import is switched off in this run');

test.beforeAll(() => {
    remove();
    php(`$shop = App\\Models\\Shop::factory()->create(["domain" => "${DOMAIN}", "name" => "Import", "plan" => "free", "currency" => "USD", "timezone" => "UTC", "onboarded_at" => now(), "installed_at" => now(), "access_token_expires_at" => now()->addYear(), "sync_status" => "completed", "last_synced_at" => now()]);
        App\\Models\\Variant::factory()->for($shop)->create(["sku" => "E2E-MUG", "lead_time_override" => 10]);
        App\\Models\\Variant::factory()->for($shop)->create(["sku" => "E2E-CUP"]);
        echo json_encode(true);`);
});
test.afterAll(() => remove());

test('a settings file is previewed, then applied', async ({ app }) => {
    await app.goto('/products');
    await settled(app);
    await app.locator('s-button', { hasText: 'Import settings' }).first().evaluate((el: HTMLElement) => el.click()); // a title-bar action: drawn by the admin, not on the page
    const modal = app.locator('s-modal#settings-import');

    await modal.locator('s-drop-zone input[type="file"]').setInputFiles({
        name: 'settings.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from('SKU,Lead time,Pack size\nE2E-MUG,21,12\nE2E-CUP,soon,\nNOT-OURS,7,\n'),
    });
    await expect(modal).toContainText('1 product will be changed.');
    await expect(modal).toContainText('Settings in this file: Lead time (days), Pack size.');
    await expect(modal).toContainText('1 row matched no product (NOT-OURS).');
    await expect(modal).toContainText('1 row is skipped, its value cannot be used: E2E-CUP (Lead time (days)).');
    await app.screenshot({ path: 'e2e-results/settings-import.png' });
    // Looking changes nothing.
    expect(mug()).toEqual({ lead_time_override: 10, pack_size: null });

    await modal.locator('s-button[slot="primary-action"]').click();
    await expect.poll(() => toasts(app)).toContain('Settings of 1 product updated');
    expect(mug()).toEqual({ lead_time_override: 21, pack_size: 12 });
});

test('a file without a setting column is refused in words', async ({ app }) => {
    await app.goto('/products');
    await settled(app);
    await app.locator('s-button', { hasText: 'Import settings' }).first().evaluate((el: HTMLElement) => el.click()); // a title-bar action: drawn by the admin, not on the page
    const modal = app.locator('s-modal#settings-import');
    await modal.locator('s-drop-zone input[type="file"]').setInputFiles({ name: 'x.csv', mimeType: 'text/csv', buffer: Buffer.from('SKU,Title\nE2E-MUG,Mug\n') });
    await expect(modal.locator('s-drop-zone')).toHaveAttribute('error', /needs a SKU or barcode column and at least one setting column/);
});
