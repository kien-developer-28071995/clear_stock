import { api, expect, open, setPlan, settled, test } from './support/app';

/**
 * The app with optional features switched off app-wide (backend config/features.php), as for a
 * trimmed App Store submission. Needs the backend running with the switches off:
 *   make e2e-features-off   (sets FEATURE_* for the run, then restores)
 * Skipped in the normal run.
 */
test.skip(!process.env.E2E_FEATURES_OFF, 'run with make e2e-features-off');

test.beforeAll(() => setPlan('growth'));

test('switched-off features are gone, not upsold', async ({ app }) => {
    await open(app, '/');
    const shop = await api<{ data: { entitlements: { features: Record<string, boolean>; what_if: boolean; transfers: boolean } } }>(app, '/shop');
    expect(shop.data.entitlements.features).toMatchObject({ what_if: false, transfers: false, flow_triggers: false, abc: false });
    expect(shop.data.entitlements.what_if).toBe(false);

    // No menu entries, and their pages are 404s.
    const nav = app.locator('s-app-nav');
    await expect(nav.locator('s-link[href="/what-if"]')).toHaveCount(0);
    await expect(nav.locator('s-link[href="/transfers"]')).toHaveCount(0);
    await open(app, '/what-if');
    await expect(app.getByText("This page doesn't exist.")).toBeVisible();

    // No ABC column or filter, no Flow card, no pricing lines, no upgrade prompts for them.
    await open(app, '/products');
    await expect(app.getByRole('combobox', { name: 'ABC class' })).toHaveCount(0);
    await open(app, '/settings');
    await expect(app.locator('s-section[heading="Shopify Flow"]')).toHaveCount(0);
    await open(app, '/plans');
    await settled(app);
    await expect(app.getByText('Shopify Flow triggers')).toHaveCount(0);
    await expect(app.getByText('Sales what-if')).toHaveCount(0);
    await app.screenshot({ path: 'e2e-results/features-off-plans.png', fullPage: true });
});
