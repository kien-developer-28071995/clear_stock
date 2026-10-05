import { api, expect, open, settled, test } from './support/app';

/**
 * One screenshot per feature that is switched on in the App Store version (v1): Starter plan and
 * the v1 switches of backend/.env.production.example. Run with `make feature-screenshots`, which
 * sets both and restores them afterwards. Written to docs/screen-feature/. Skipped in the normal
 * E2E run. Nothing is saved: dialogs are opened and left, the what-if only reads.
 */
test.skip(!process.env.FEATURE_SCREENSHOTS, 'run with make feature-screenshots');
test.use({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });

const OUT = '../docs/screen-feature';

/** Switches the App Store version keeps off: none of them may show up in a screenshot. */
const V1_OFF = [
    'shopify_purchase_orders', 'supplier_emails', 'locations', 'transfers', 'realtime_alerts', 'flow_triggers',
    'slack_alerts', 'order_exclusions', 'location_exclusions', 'alternate_suppliers', 'saved_views', 'size_runs',
];

test('a screenshot of every feature that is on', async ({ app }) => {
    test.setTimeout(240_000);
    // The setup guide and tips would repeat on every page; the admin sidebar is a row of links in the stub.
    await app.route('**/api/setup-guide', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.data = { ...body.data, dismissed: true, tips_dismissed: ['home_actions', 'home_runway', 'product_explanation'] };
        await route.fulfill({ response, json: body });
    });
    await app.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const style = document.createElement('style');
            style.textContent = 's-app-nav { display: none !important; } body { padding-top: 8px; }';
            document.head.appendChild(style);
        });
    });
    const shot = async (name: string) => {
        await settled(app);
        await app.waitForTimeout(500); // web components and their icons finish rendering
        await app.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
    };
    const page = async (route: string, name: string) => {
        await open(app, route);
        await shot(name);
    };

    await open(app, '/');
    const shop = await api<{ data: { entitlements: { plan: string; features: Record<string, boolean> } } }>(app, '/shop');
    expect(shop.data.entitlements.plan).toBe('starter');
    const on = Object.entries(shop.data.entitlements.features).filter(([, v]) => v).map(([k]) => k).sort();
    const off = Object.entries(shop.data.entitlements.features).filter(([, v]) => !v).map(([k]) => k).sort();
    expect(off).toEqual([...V1_OFF].sort());
    console.log(`ON (${on.length}): ${on.join(', ')}`);
    console.log(`OFF (${off.length}): ${off.join(', ')}`);

    const due = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts?status=reorder_now')).data[0].variant_id;

    // Core: forecasts, what to order, explanations.
    await page('/', '01-home');
    await page('/reorder', '02-reorder-to-order');
    await app.locator('s-button', { hasText: 'Mark as ordered' }).first().click();
    await shot('03-reorder-mark-as-ordered');
    await page('/reorder/orders', '04-reorder-orders-placed');

    await page('/products', '05-products-list');
    await app.locator('s-press-button', { hasText: 'More filters' }).click();
    await shot('06-products-filters-abc-trend-vendor');
    await page('/products/bundles', '07-products-bundles');
    await page('/products/costs', '08-products-unit-costs');

    await page(`/products/${due}`, '09-product-forecast-explanation');
    await page(`/products/${due}?tab=settings`, '10-product-settings');

    // Insights.
    await page('/insights', '11-insights-runway-lost-sales-stock-history');
    await page('/insights?tab=excess', '12-insights-overstock-slow-clearance');
    await page('/insights?tab=products', '13-insights-abc-accuracy');

    // Planning (Starter).
    await page('/planning', '14-planning-purchase-plan');
    await page('/planning/budget', '15-planning-order-budget');
    await open(app, '/planning/what-if');
    await expect(app.locator('s-table-body s-table-row').first()).toBeVisible({ timeout: 15_000 }).catch(() => undefined);
    await shot('16-planning-what-if');
    await page('/planning/events', '17-planning-sales-events');

    // Suppliers.
    await page('/suppliers', '18-suppliers');
    await page('/suppliers/from-vendors', '19-suppliers-from-shopify-vendors');
    await page('/suppliers/import', '20-suppliers-import-purchase-orders');

    // Settings, data check, plans.
    await page('/settings', '21-settings-forecast');
    await page('/settings?tab=alerts', '22-settings-alerts-weekly-summary');
    await page('/settings?tab=general', '23-settings-general');
    await page('/data-health', '24-data-check');
    await page('/plans', '25-plans');

    // What is off must be gone: no tab, section or page for it.
    await open(app, `/products/${due}`);
    await expect(app.locator('s-press-button', { hasText: 'Suppliers' })).toHaveCount(0);
    await open(app, '/settings');
    await expect(app.locator('s-section[heading="Orders left out of the forecast"]')).toHaveCount(0);
    await expect(app.locator('s-section[heading="Locations counted as stock"]')).toHaveCount(0);
    await open(app, '/settings?tab=alerts');
    await expect(app.getByLabel('Slack webhook URL')).toHaveCount(0);
    await open(app, '/reorder/transfers');
    await expect(app.getByText("This page doesn't exist.")).toBeVisible();
});
