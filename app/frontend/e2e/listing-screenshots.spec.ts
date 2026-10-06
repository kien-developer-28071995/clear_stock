import { api, expect, open, settled, test } from './support/app';

/**
 * App Store listing screenshots (1600x900, English) of the v1 app: Starter plan, v1 feature
 * switches (docs/APP_STORE.md). Run with `make listing-screenshots`, which sets both and restores
 * them afterwards. Written to docs/listing/screenshots/. Skipped in the normal E2E run.
 *
 * Cosmetic only, nothing is saved: the setup guide and tips are shown as dismissed, the admin
 * navigation (the sidebar inside Shopify) is hidden since the stub renders it as links, and the
 * dev store's currency is shown as USD (its sample prices are USD-like numbers; values unchanged).
 */
test.skip(!process.env.LISTING_SCREENSHOTS, 'run with make listing-screenshots');
test.use({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 1 });

const OUT = '../../docs/listing/screenshots';

test('listing screenshots', async ({ app }) => {
    await app.route('**/api/setup-guide', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.data = { ...body.data, dismissed: true, tips_dismissed: ['home_actions', 'home_runway', 'product_explanation'] };
        await route.fulfill({ response, json: body });
    });
    await app.route(/\/api\/(shop|dashboard|billing|what-if|forecasts)/, async (route) => {
        const response = await route.fetch();
        const text = (await response.text()).replace(/"currency":"[A-Z]{3}"/g, '"currency":"USD"');
        await route.fulfill({ response, body: text });
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
        await app.waitForTimeout(400); // web components finish their layout
        await app.screenshot({ path: `${OUT}/${name}.png` });
    };

    // v1 guard: the switches and plan the listing shows.
    await open(app, '/');
    const shop = await api<{ data: { entitlements: { plan: string; features: Record<string, boolean> } } }>(app, '/shop');
    expect(shop.data.entitlements.plan).toBe('starter');
    expect(shop.data.entitlements.features).toMatchObject({ locations: false, flow_triggers: false, supplier_emails: false, what_if: true });

    // 1. Home: what to reorder today.
    await shot('01-home');

    // 2. Every product with its forecast.
    await open(app, '/products');
    await shot('02-products');

    // 3. A product to reorder (still in stock), with the explanation in view.
    await open(app, '/products?status=reorder_now');
    await app.locator('s-table-body s-table-row', { has: app.locator('s-badge', { hasText: 'Reorder now' }) }).first().locator('s-link').first().click();
    await settled(app);
    await expect(app.locator('s-section[heading="Why these numbers?"]')).toBeVisible();
    await shot('03-product-explanation');

    // 4. Reorder list, ready to export as a purchase order.
    await open(app, '/reorder');
    await shot('04-reorder');

    // 5. Insights: stock runway, ABC classes, money tied up.
    await open(app, '/insights');
    await shot('05-insights');

    // 6. Sales what-if: +20%.
    await open(app, '/what-if?growth=20');
    await expect(app.getByText(/Sales \+20%/)).toBeVisible();
    await shot('06-what-if');

    // 7. Plans: flat price, price lock.
    await open(app, '/plans');
    await shot('07-plans');
});
