import { api, expect, open, setPlan, settled, test } from './support/app';

/**
 * The app with every optional feature switched off app-wide (backend config/features.php): what
 * is left is the core (forecasts, explanations, product settings, suppliers). Needs the backend
 * running with the switches off:
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

test('with every switch off the core still works and nothing switched off is shown', async ({ app }) => {
    test.setTimeout(180_000);
    // No screen may call an API of a switched-off feature, and none may crash.
    const failed: string[] = [];
    app.on('response', (r) => {
        if (r.url().includes('/api/') && r.status() >= 400) failed.push(`${r.status()} ${new URL(r.url()).pathname}`);
    });
    app.on('pageerror', (e) => failed.push(`page error: ${e.message}`));

    await open(app, '/');
    const shop = await api<{ data: { review_prompt: boolean; entitlements: { features: Record<string, boolean> } } }>(app, '/shop');
    expect(Object.entries(shop.data.entitlements.features).filter(([, on]) => on).map(([k]) => k)).toEqual([]);
    const variant = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id;
    const gone = async (text: string | RegExp) => expect(app.getByText(text, { exact: typeof text === 'string' })).toHaveCount(0);

    // Home and the reorder list: no "mark as ordered", no export, no tabs (one page left in the group).
    await settled(app);
    await open(app, '/reorder');
    await settled(app);
    await expect(app.locator('s-button', { hasText: 'Mark as ordered' })).toHaveCount(0);
    await expect(app.locator('s-button', { hasText: /^Snooze$/ })).toHaveCount(0);
    expect(shop.data.review_prompt).toBe(false);
    await expect(app.locator('s-button', { hasText: 'Export purchase order' })).toHaveCount(0);
    await expect(app.locator('s-press-button', { hasText: 'Orders placed' })).toHaveCount(0);

    // Product list: no bundle/cost tabs, export, saved views, trend or ABC.
    await open(app, '/products');
    await settled(app);
    await expect(app.locator('s-table-body s-table-row').first()).toBeVisible();
    await expect(app.locator('s-press-button', { hasText: 'Bundles' })).toHaveCount(0);
    await expect(app.locator('s-press-button', { hasText: 'Unit costs' })).toHaveCount(0);
    await expect(app.locator('s-button', { hasText: 'Export list (CSV)' })).toHaveCount(0);
    await app.locator('s-press-button', { hasText: 'More filters' }).click();
    await expect(app.getByRole('combobox', { name: 'Trend' })).toHaveCount(0);
    await expect(app.getByRole('combobox', { name: 'ABC class' })).toHaveCount(0);
    await expect(app.getByRole('textbox', { name: 'View name' })).toHaveCount(0);

    // Product page: the forecast and its explanation stay; no supplier tab, cost or profile field.
    await open(app, `/products/${variant}`);
    await settled(app);
    await expect(app.locator('s-section[heading="Why these numbers?"]')).toBeVisible();
    await expect(app.locator('s-press-button', { hasText: 'Suppliers' })).toHaveCount(0);
    await expect(app.locator('s-press-button', { hasText: 'History' })).toHaveCount(0);
    await expect(app.locator('s-section[heading="Expected sales"]')).toHaveCount(0);
    await expect(app.locator('s-button', { hasText: 'Mark as ordered' })).toHaveCount(0);
    await open(app, `/products/${variant}?tab=settings`);
    await settled(app);
    await expect(app.locator('s-section[heading="Product settings"]')).toBeVisible();
    await expect(app.getByLabel('Unit cost')).toHaveCount(0);
    await expect(app.getByLabel('Sales rate for this product')).toHaveCount(0);
    await expect(app.getByText('Similar product (for new products)')).toHaveCount(0);

    // Insights: runway and slow stock stay; the reports with a switch are gone.
    await open(app, '/insights');
    await settled(app);
    await expect(app.locator('s-section[heading="Stock runway"]')).toBeVisible();
    for (const heading of ['Inventory value over time', 'Sales lost to stock-outs', 'What to clear', 'ABC classes', 'Forecast accuracy']) {
        await expect(app.locator(`s-section[heading="${heading}"]`)).toHaveCount(0);
    }
    await open(app, '/insights?tab=excess');
    await settled(app);
    await expect(app.locator('s-section[heading="What to clear"]')).toHaveCount(0);

    // Suppliers: the list and its form stay; no shortcuts from vendors or files, no export.
    await open(app, '/suppliers');
    await settled(app);
    await expect(app.locator('s-button[href="/suppliers/from-vendors"]')).toHaveCount(0);
    await expect(app.locator('s-button[href="/suppliers/import"]')).toHaveCount(0);

    // Settings: defaults and language stay; no alerts tab, no optional sections.
    await open(app, '/settings');
    await expect(app.locator('s-button', { hasText: 'Send feedback' })).toHaveCount(0);
    await settled(app);
    await expect(app.getByLabel('Default lead time')).toBeVisible();
    await expect(app.locator('s-press-button', { hasText: 'Alerts and emails' })).toHaveCount(0);
    await expect(app.getByLabel('How the sales rate is averaged')).toHaveCount(0);
    await expect(app.getByText('Ignore one-off sales spikes')).toHaveCount(0);
    for (const heading of ['Orders left out of the forecast', 'Locations counted as stock']) {
        await expect(app.locator(`s-section[heading="${heading}"]`)).toHaveCount(0);
    }
    await open(app, '/settings?tab=general');
    await settled(app);
    await expect(app.getByLabel('App language')).toBeVisible();
    for (const heading of ['Unit costs', 'Data check']) await expect(app.locator(`s-section[heading="${heading}"]`)).toHaveCount(0);

    // Plans: only the core lines are sold.
    await open(app, '/plans');
    await settled(app);
    await expect(app.getByText('Stock-out dates and reorder suggestions').first()).toBeVisible();
    for (const line of ['Bundles and kits', /Email alerts/, 'Purchase order export (CSV)', 'ABC classes by revenue', 'Stock by location']) await gone(line);

    expect(failed, 'screens of the core must not call a switched-off API').toEqual([]);

    // Their pages are not there.
    for (const path of ['/reorder/orders', '/reorder/transfers', '/products/bundles', '/products/costs', '/planning', '/planning/budget', '/planning/what-if', '/planning/events', '/suppliers/import', '/suppliers/from-vendors', '/data-health']) {
        await open(app, path);
        await expect(app.getByText("This page doesn't exist."), path).toBeVisible();
    }
    await app.screenshot({ path: 'e2e-results/all-off-not-found.png' });
});
