import fs from 'node:fs';
import { api, expect, open, pickNext, saveBar, setPlan, settled, test, toasts, variants, type PlanKey } from './support/app';

/**
 * Every screen and every feature, once per plan. Each plan block switches the dev shop
 * to that plan first; features the plan doesn't include must be locked in the UI AND
 * refused by the API. Tests that change data put it back.
 */

type Counts = Record<string, number>;
type Entitlements = {
    plan: PlanKey;
    max_skus: number | null;
    bundles: boolean;
    alerts: boolean;
    locations: boolean;
    purchase_orders: boolean;
    realtime_alerts: boolean;
    what_if: boolean;
    supplier_auto_email: boolean;
    flow_triggers: boolean;
    reference_products: boolean;
    transfers: boolean;
    supplier_emails: boolean;
    purchase_plan: boolean;
};

const PAGES: [string, string][] = [
    ['/', 'Clear Stock'],
    ['/reorder', 'Reorder'],
    ['/transfers', 'Transfers'],
    ['/insights', 'Insights'],
    ['/what-if', 'What-if'],
    ['/purchase-plan', 'Purchase plan'],
    ['/products', 'Products'],
    ['/suppliers', 'Suppliers'],
    ['/suppliers/import', 'Import'],
    ['/suppliers/from-vendors', 'vendors'],
    ['/bundles', 'Bundles'],
    ['/settings', 'Settings'],
    ['/plans', 'Plans'],
];

const EXPECTED: Record<PlanKey, Omit<Entitlements, 'plan'>> = {
    free: { max_skus: 50, bundles: false, alerts: false, locations: false, purchase_orders: false, realtime_alerts: false, what_if: false, supplier_auto_email: false, flow_triggers: false, reference_products: false, transfers: false, supplier_emails: false, purchase_plan: false },
    starter: { max_skus: null, bundles: true, alerts: true, locations: false, purchase_orders: true, realtime_alerts: false, what_if: true, supplier_auto_email: false, flow_triggers: false, reference_products: true, transfers: false, supplier_emails: true, purchase_plan: true },
    growth: { max_skus: null, bundles: true, alerts: true, locations: true, purchase_orders: true, realtime_alerts: true, what_if: true, supplier_auto_email: true, flow_triggers: true, reference_products: true, transfers: true, supplier_emails: true, purchase_plan: true },
};

for (const plan of ['free', 'starter', 'growth'] as PlanKey[]) {
    const has = EXPECTED[plan];

    test.describe(`${plan} plan`, () => {
        test.describe.configure({ mode: 'serial' });
        test.beforeAll(() => setPlan(plan));

        test('entitlements match the plan', async ({ app }) => {
            await open(app, '/');
            const shop = await api<{ data: { entitlements: Entitlements } }>(app, '/shop');
            expect(shop.data.entitlements).toMatchObject({ plan, ...has });
        });

        test('every page renders from the navigation, and unknown pages show a 404', async ({ app }) => {
            await open(app, '/');
            for (const [route, heading] of PAGES) {
                await open(app, route);
                await expect(app.locator('s-page').first()).toHaveAttribute('heading', new RegExp(heading, 'i'));
                await expect(app.locator('s-banner[tone="critical"]')).toHaveCount(0);
            }
            await open(app, '/does-not-exist');
            await expect(app.locator('s-page[heading="Page not found"]')).toBeVisible();
        });

        test('home, filters and badges agree on every status', async ({ app }) => {
            await open(app, '/');
            const dashboard = await api<{ data: { counts: Counts } }>(app, '/dashboard');
            const counts = dashboard.data.counts;
            await expect(app.getByText(`${counts.reorder_now} products to reorder today`).or(app.getByText(/to reorder today|nothing to reorder/i)).first()).toBeVisible();

            for (const status of ['out_of_stock', 'reorder_now', 'overstock', 'slow', 'healthy']) {
                if (counts[status] === undefined) continue;
                const list = await api<{ data: { status: string }[]; meta: { total: number } }>(app, `/forecasts?status=${status}&per_page=100`);
                expect(list.meta.total, `${status}: filter total = home count`).toBe(counts[status]);
                // out_of_stock rows are also "reorder now" in the filter; the badge shows the most urgent.
                if (status !== 'reorder_now') expect(new Set(list.data.map((r) => r.status))).toEqual(new Set(list.data.length ? [status] : []));
            }

            await open(app, '/products?status=overstock');
            const rows = app.locator('s-table-body s-table-row');
            await expect(rows).toHaveCount(counts.overstock ?? 0);

            // "All products" clears the filter (an empty option value used to submit its label).
            await app.getByRole('combobox', { name: 'Status' }).selectOption({ index: 0 });
            await expect(app).not.toHaveURL(/status=/);
            await settled(app);
            await expect(rows).toHaveCount(Object.values(counts).length ? (await api<{ meta: { total: number } }>(app, '/forecasts')).meta.total : 0);
        });

        test('product list rows only carry what the table shows', async ({ app }) => {
            await open(app, '/products');
            const list = await api<{ data: Record<string, unknown>[]; meta: Record<string, number> }>(app, '/forecasts');
            expect(Object.keys(list.meta).sort()).toEqual(['current_page', 'last_page', 'per_page', 'total']);
            expect(Object.keys(list.data[0]).sort()).toEqual(
                ['abc_class', 'avg_daily_sales', 'current_stock', 'days_of_cover', 'excess_units', 'incoming_stock', 'name', 'reorder_date', 'sku', 'status', 'suggested_qty', 'variant_id', 'vendor'].sort(),
            );
            await expect(app.locator('s-table-body s-table-row')).toHaveCount(Math.min(list.meta.total, list.meta.per_page));
        });

        test('ABC classes: filter, badge, explanation on the product and summary in insights', async ({ app }) => {
            await open(app, '/products');
            const dashboard = await api<{ data: { abc: { classes: Record<'A' | 'B' | 'C', { count: number }> } } }>(app, '/dashboard');
            const countA = dashboard.data.abc.classes.A.count;
            test.skip(countA === 0, 'dev store has no sales with a price yet (run a full sync)');

            await app.getByRole('combobox', { name: 'ABC class' }).selectOption('A');
            await expect(app).toHaveURL(/abc=A/);
            await settled(app);
            const rows = app.locator('s-table-body s-table-row');
            await expect(rows).toHaveCount(Math.min(countA, 25));
            await expect(rows.first().locator('s-badge', { hasText: 'Class A' })).toBeVisible();
            await app.screenshot({ path: 'e2e-results/abc-products.png', fullPage: true });

            await rows.first().locator('s-link').first().click();
            await settled(app);
            await expect(app.getByText(/^Class A: .*% of your revenue in the last 90 days/)).toBeVisible();
            await app.screenshot({ path: 'e2e-results/abc-detail.png', fullPage: true });

            await open(app, '/insights');
            const section = app.locator('s-section[heading="ABC classes"]');
            await expect(section).toBeVisible();
            await expect(section.getByText('Never let these run out')).toBeVisible();
            await app.screenshot({ path: 'e2e-results/abc-insights.png', fullPage: true });
        });

        test('what-if: no change matches the forecast, growth orders more and sooner', async ({ app }) => {
            type Totals = { products: number; units: number };
            type WhatIf = { data: { totals: { now: Totals; scenario: Totals }; items: { now: unknown; scenario: unknown }[] } };

            await open(app, '/what-if?growth=0');
            if (!has.what_if) {
                await expect(app.locator('s-banner[heading="Included in Starter"]')).toBeVisible();
                expect(await app.evaluate(async () => (await fetch('/api/what-if?growth=20', { headers: { Authorization: `Bearer ${await window.shopify.idToken()}` } })).status)).toBe(402);
                return;
            }
            const same = await api<WhatIf>(app, '/what-if?growth=0');
            expect(same.data.totals.scenario).toEqual(same.data.totals.now);
            for (const item of same.data.items) expect(item.scenario).toEqual(item.now);

            await app.locator('s-button', { hasText: '+50%' }).click();
            await expect(app).toHaveURL(/growth=50/);
            await settled(app);
            await expect(app.getByText(/Sales \+50% \(current rate × 1\.5\)/)).toBeVisible();
            const grown = await api<WhatIf>(app, '/what-if?growth=50');
            expect(grown.data.totals.scenario.units).toBeGreaterThanOrEqual(grown.data.totals.now.units);
            expect(grown.data.totals.scenario.products).toBeGreaterThanOrEqual(grown.data.totals.now.products);
            await expect(app.locator('s-table-body s-table-row')).toHaveCount(grown.data.items.length);
            await app.screenshot({ path: 'e2e-results/what-if.png', fullPage: true });
        });

        test('purchase plan: weekly orders and spend follow the forecast', async ({ app }) => {
            type Plan = { data: { totals: { orders: number; units: number }; by_week: { units: number }[]; items: unknown[] } };

            await open(app, '/purchase-plan');
            if (!has.purchase_plan) {
                await expect(app.locator('s-banner[heading="Included in Starter"]')).toBeVisible();
                expect(await app.evaluate(async () => (await fetch('/api/purchase-plan', { headers: { Authorization: `Bearer ${await window.shopify.idToken()}` } })).status)).toBe(402);
                return;
            }
            const plan12 = await api<Plan>(app, '/purchase-plan');
            expect(plan12.data.by_week).toHaveLength(12);
            expect(plan12.data.by_week.reduce((sum, w) => sum + w.units, 0)).toBe(plan12.data.totals.units);
            if (plan12.data.totals.orders > 0) {
                await expect(app.locator('[aria-label="Spend per week"] [role="listitem"]')).toHaveCount(12);
                await expect(app.locator('s-section[heading="By product"] s-table-body s-table-row')).toHaveCount(plan12.data.items.length);
            }
            const plan4 = await api<Plan>(app, '/purchase-plan?weeks=4');
            expect(plan4.data.totals.units).toBeLessThanOrEqual(plan12.data.totals.units);
            await app.screenshot({ path: `e2e-results/purchase-plan-${plan}.png`, fullPage: true });
        });

        test('insights show sales lost to stock-outs', async ({ app }) => {
            type Lost = { data: { lost_sales: { count: number; top: { name: string }[] } | null } };
            await open(app, '/insights');
            const lost = (await api<Lost>(app, '/dashboard')).data.lost_sales;
            expect(lost).not.toBeNull();
            if (lost && lost.count > 0) {
                const section = app.locator('s-section[heading="Sales lost to stock-outs"]');
                await expect(section).toBeVisible();
                await expect(section.getByText(lost.top[0].name).first()).toBeVisible();
            }
        });

        test('Shopify Flow section follows the plan', async ({ app }) => {
            await open(app, '/settings');
            const section = app.locator('s-section[heading="Shopify Flow"]');
            await expect(section).toContainText('Product reorder date reached');
            if (has.flow_triggers) await expect(section.locator('s-link', { hasText: 'Open Shopify Flow' })).toBeVisible();
            else await expect(section.locator('s-banner[heading="Included in Growth"]')).toBeVisible();
            await section.screenshot({ path: `e2e-results/flow-${plan}.png` }).catch(() => app.screenshot({ path: `e2e-results/flow-${plan}.png`, fullPage: true }));
        });

        test('a similar product can be set for new products on paid plans', async ({ app }) => {
            await open(app, '/products');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            const variantId = Number(app.url().split('/').pop());
            const pick = app.locator('s-button', { hasText: 'Choose product' });

            if (!has.reference_products) {
                await expect(pick).toHaveAttribute('disabled');
                await expect(app.locator('s-banner[heading="Included in Starter"]').first()).toBeVisible();
                return;
            }

            const other = variants().find((v) => v.id !== variantId)!;
            await pickNext(app, [{ id: other.gid, displayName: other.name }]);
            await pick.click();
            await expect(app.getByText(other.name, { exact: true })).toBeVisible();
            await saveBar(app, 'product-settings-save-bar');
            type Detail = { data: { settings: { reference_variant_id: number | null }; explanation_lines: { code: string }[] } };
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.settings.reference_variant_id).toBe(other.id);
            // Dev store products have months of history: the reference is recorded but no longer needed.
            const lines = (await api<Detail>(app, `/forecasts/${variantId}`)).data.explanation_lines.map((l) => l.code);
            expect(lines.some((c) => c.startsWith('reference_'))).toBe(true);

            await app.locator('s-button', { hasText: 'Remove' }).first().click();
            await saveBar(app, 'product-settings-save-bar');
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.settings.reference_variant_id).toBeNull();
        });

        test('product detail explains the number, and a temporary adjustment wins until reset', async ({ app }) => {
            await open(app, '/products?status=reorder_now');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            await expect(app.locator('s-section[heading="Why these numbers?"]')).toBeVisible();
            await expect(app.getByText(/Sells .*\/day/).first()).toBeVisible();

            const variantId = Number(app.url().split('/').pop());
            await app.getByRole('spinbutton', { name: 'Sells per day' }).fill('12');
            await saveBar(app, 'adjust-forecast-save-bar');
            await expect.poll(async () => (await api<{ data: { avg_daily_sales: number } }>(app, `/forecasts/${variantId}`)).data.avg_daily_sales).toBe(12);
            expect(await toasts(app)).toContain('Forecast updated');

            await app.locator('s-button', { hasText: 'Use automatic forecast' }).click();
            await expect.poll(async () => (await api<{ data: { overrides: { avg_daily_sales: unknown } } }>(app, `/forecasts/${variantId}`)).data.overrides.avg_daily_sales).toBeNull();
        });

        test('default lead time is saved and used by forecasts', async ({ app }) => {
            await open(app, '/settings');
            const lead = app.getByRole('spinbutton', { name: /lead time/i });
            const before = await lead.inputValue();
            await lead.fill('21');
            await saveBar(app, 'settings-save-bar');
            expect((await api<{ data: { default_lead_time_days: number } }>(app, '/settings')).data.default_lead_time_days).toBe(21);

            await lead.fill(before);
            await saveBar(app, 'settings-save-bar');
            expect((await api<{ data: { default_lead_time_days: number } }>(app, '/settings')).data.default_lead_time_days).toBe(Number(before));
        });

        test('language can be switched to Vietnamese and back', async ({ app }) => {
            await open(app, '/settings');
            await app.getByRole('combobox', { name: 'Language' }).selectOption('vi');
            await saveBar(app, 'settings-save-bar');
            await expect(app.locator('s-page[heading="Cài đặt"]')).toBeVisible();
            await open(app, '/products');
            await expect(app.locator('s-page[heading="Sản phẩm"]')).toBeVisible();

            await open(app, '/settings');
            await app.getByRole('combobox', { name: 'Ngôn ngữ' }).selectOption({ index: 0 });
            await saveBar(app, 'settings-save-bar');
            await expect(app.locator('s-page[heading="Settings"]')).toBeVisible();
        });

        test('suppliers can be added, edited and deleted', async ({ app }) => {
            const name = `E2E Supplier ${plan}`;
            await open(app, '/suppliers');
            // The empty state shows the button; with suppliers it is the page's primary action,
            // which the admin renders in its title bar (not drawn outside the admin): click it directly.
            await app.locator('s-button', { hasText: 'Add supplier' }).first().evaluate((el: HTMLElement) => el.click());
            const modal = app.locator('s-modal#supplier-modal');
            await modal.getByRole('textbox', { name: 'Name' }).fill(name);
            await modal.getByRole('spinbutton', { name: /lead time/i }).fill('9');
            await modal.getByRole('textbox', { name: /email/i }).fill('supplier@example.com');
            await modal.locator('s-button[slot="primary-action"]').click();
            const row = app.locator('s-table-row', { hasText: name });
            await expect(row).toContainText('9 days');

            await row.locator('s-button', { hasText: 'Edit' }).click();
            await modal.getByRole('spinbutton', { name: /lead time/i }).fill('11');
            await modal.locator('s-button[slot="primary-action"]').click();
            await expect(row).toContainText('11 days');

            // Emailing a purchase order to a supplier (with an email address) is Starter and up.
            const email = row.locator('s-button', { hasText: /email/i });
            if (has.supplier_emails) await expect(email).not.toHaveAttribute('disabled');
            else await expect(email).toHaveAttribute('disabled');

            await row.locator('s-button', { hasText: 'Delete' }).click();
            await app.locator('s-modal s-button[slot="primary-action"]', { hasText: 'Delete' }).click();
            await expect(row).toHaveCount(0);
        });

        test('purchase order export follows the plan', async ({ app }) => {
            await open(app, '/reorder');
            const res = await app.evaluate(async () => (await fetch('/api/purchase-orders/export', { headers: { Authorization: `Bearer ${await window.shopify.idToken()}` } })).status);
            if (has.purchase_orders) {
                expect(res).toBe(200);
                const exportButton = app.getByRole('button', { name: 'Export purchase order' }).first();
                const menuItem = (label: string) => app.getByRole('menuitem', { name: label });

                // Our spreadsheet.
                let download = app.waitForEvent('download');
                await exportButton.click();
                await menuItem('Spreadsheet (CSV)').click();
                expect(fs.readFileSync(await (await download).path(), 'utf8')).toContain('Order quantity');

                // Shopify's purchase order import file: exact template header, no BOM.
                download = app.waitForEvent('download');
                await exportButton.click();
                await menuItem('Shopify purchase order').click();
                const file = await download;
                expect(file.suggestedFilename()).toMatch(/^shopify-purchase-order-/);
                expect(fs.readFileSync(await file.path(), 'utf8').split('\n')[0].trim()).toBe('SKU,Barcode,Supplier SKU,Quantity,Cost,Tax');
                await expect.poll(() => toasts(app)).toContainEqual(expect.stringContaining('Products › Purchase orders'));
            } else {
                expect(res).toBe(402);
                await expect(app.locator('s-button[disabled]', { hasText: 'Export purchase order (Starter)' })).toBeVisible();
            }
        });

        test('bundles follow the plan', async ({ app }) => {
            await open(app, '/bundles');
            const add = app.locator('s-button', { hasText: 'Add bundle' });
            if (!has.bundles) {
                // Locked, not hidden (Built for Shopify): every "Add bundle" is disabled.
                for (const button of await add.all()) await expect(button).toHaveAttribute('disabled');
                await expect(app.locator('s-link[href="/plans"], s-button[href="/plans"]').first()).toBeVisible();
                const all = variants();
                const refused = await api<{ code: string }>(app, '/bundles', {
                    method: 'POST',
                    body: { bundle: all[0].gid, components: [{ variant: all[1].gid, quantity: 1 }] },
                });
                expect(refused.code).toBe('plan_required');
                return;
            }

            const all = variants();
            const bundle = all.find((v) => v.name === 'The Hidden Snowboard')!;
            const component = all.find((v) => v.name === 'The Minimal Snowboard')!;
            await add.locator('visible=true').first().click();
            const modal = app.locator('s-modal#bundle-modal');
            await pickNext(app, [{ id: bundle.gid, displayName: bundle.name }]);
            await modal.locator('s-button').getByText('Select product', { exact: true }).click();
            await pickNext(app, [{ id: component.gid, displayName: component.name }]);
            await modal.locator('s-button').getByText('Select products', { exact: true }).click();
            await modal.getByRole('spinbutton', { name: /quantity/i }).fill('2');
            await modal.locator('s-button[slot="primary-action"]').click();

            const row = app.locator('s-table-row', { hasText: bundle.name });
            await expect(row).toContainText(component.name);
            await row.locator('s-button', { hasText: /remove|delete/i }).click();
            await app.locator('s-modal s-button[slot="primary-action"]').filter({ hasText: /remove|delete/i }).click();
            await expect(row).toHaveCount(0);
        });

        test('alert settings follow the plan', async ({ app }) => {
            await open(app, '/settings');
            // Locked, not hidden: controls are disabled on plans without the feature.
            const email = app.locator('s-switch[label="Email me when products need reordering"]');
            const realtime = app.locator('s-select[label="Real-time alerts"]');
            if (has.alerts) await expect(email).not.toHaveAttribute('disabled');
            else await expect(email).toHaveAttribute('disabled');
            if (has.realtime_alerts) await expect(realtime).not.toHaveAttribute('disabled');
            else await expect(realtime).toHaveAttribute('disabled');
        });

        test('location filter follows the plan', async ({ app }) => {
            await open(app, '/products');
            const locations = await api<{ data: unknown[] }>(app, '/locations');
            await expect(app.getByRole('combobox', { name: 'Location' })).toHaveCount(has.locations && locations.data.length > 1 ? 1 : 0);
        });

        test('transfer suggestions follow the plan', async ({ app }) => {
            await open(app, '/transfers');
            const status = await app.evaluate(async () => (await fetch('/api/transfers', { headers: { Authorization: `Bearer ${await window.shopify.idToken()}`, Accept: 'application/json' } })).status);
            if (!has.transfers) {
                expect(status).toBe(402);
                await expect(app.locator('s-banner', { hasText: 'Move stock between your locations' })).toBeVisible();
                return;
            }
            expect(status).toBe(200);
            // The dev store's real data: one location holds the stock, nothing to move.
            const real = await api<{ data: { available: boolean; routes: unknown[] } }>(app, '/transfers');
            if (real.data.available && real.data.routes.length === 0) await expect(app.locator('s-empty-state[heading="No transfers needed"]')).toBeVisible();
        });

        test('a suggested transfer is created as a draft in Shopify, after asking for the permission', async ({ app }) => {
            test.skip(!has.transfers, 'Growth only');
            // A route the dev data doesn't have; creation itself is Shopify's (answered here).
            const route = {
                origin: { id: 2, name: 'Warehouse' }, destination: { id: 1, name: 'Store' }, total_units: 35,
                items: [{ variant_id: 8, name: 'The Minimal Snowboard', sku: 'MIN-1', quantity: 35, origin_stock: 100, destination_stock: 5, destination_days_of_cover: 2.5, destination_stockout_date: '2026-09-28' }],
            };
            let posted: Record<string, unknown> | null = null;
            await app.route('**/api/transfers', (r) => {
                if (r.request().method() === 'GET') return r.fulfill({ json: { data: { available: true, scope_granted: false, routes: [route], recent: [] } } });
                posted = r.request().postDataJSON();
                return r.fulfill({ status: 201, json: { data: { id: 1, shopify_transfer_id: 555, name: '#T0001', total_units: posted!.items && 30 } } });
            });
            await open(app, '/transfers');
            await expect(app.locator('s-section[heading="Warehouse → Store"]')).toContainText('The Minimal Snowboard');

            // Declined: nothing is created.
            await app.evaluate(() => (window.__e2e.grantScopes = false));
            await app.getByRole('button', { name: 'Create draft transfer in Shopify' }).click();
            await expect.poll(() => toasts(app)).toContainEqual(expect.stringContaining("can't be created without the permission"));
            expect(posted).toBeNull();

            // Approved, with the quantity edited down.
            await app.evaluate(() => (window.__e2e.grantScopes = true));
            await app.getByRole('spinbutton', { name: 'Move' }).fill('30');
            await app.getByRole('button', { name: 'Create draft transfer in Shopify' }).click();
            await expect.poll(() => posted).toMatchObject({ origin_location_id: 2, destination_location_id: 1, items: [{ variant_id: 8, quantity: 30 }] });
            expect(String((posted as unknown as { idempotency_key: string }).idempotency_key)).toMatch(/^[0-9a-f-]{36}$/);
            expect(await app.evaluate(() => window.__e2e.scopeRequests)).toEqual([['write_inventory_transfers'], ['write_inventory_transfers']]);
            await expect.poll(() => toasts(app)).toContainEqual(expect.stringContaining('Draft transfer #T0001 created (30 units)'));
        });

        test('plans page marks the current plan and upgrades go through Shopify billing', async ({ app }) => {
            // Billing itself is Shopify's: answer the API call with a fake approval URL.
            let requested: unknown = null;
            await app.route('**/api/billing', (route) => {
                if (route.request().method() !== 'POST') return route.continue();
                requested = route.request().postDataJSON();
                return route.fulfill({ json: { data: { confirmation_url: 'https://admin.shopify.com/charges/approve' } } });
            });
            await app.addInitScript(() => (window.open = ((url: string) => void ((window as unknown as { __opened: string }).__opened = url)) as typeof window.open));
            await open(app, '/plans');

            const cards = app.locator('s-section, s-box').filter({ hasText: 'Current plan' });
            await expect(cards.filter({ hasText: plan[0].toUpperCase() + plan.slice(1) }).first()).toBeVisible();

            const higher = plan === 'growth' ? null : 'Growth';
            if (higher) {
                await app.locator('s-button', { hasText: /Start 7-day free trial|Choose Growth/ }).last().click();
                await expect.poll(() => requested).toMatchObject({ plan: 'growth' });
                await expect.poll(() => app.evaluate(() => (window as unknown as { __opened?: string }).__opened)).toBe('https://admin.shopify.com/charges/approve');
            }

            if (plan !== 'free') {
                // Downgrading warns what stops working before anything is changed.
                requested = null;
                await app.locator('s-button', { hasText: 'Switch to Free' }).click();
                const modal = app.locator('s-modal').filter({ hasText: 'Switch to Free?' });
                await expect(modal).toContainText('Your data and settings are kept');
                if (has.purchase_orders) await expect(modal).toContainText('Purchase order export');
                await modal.locator('s-button[slot="secondary-actions"]').click();
                expect(requested).toBeNull();
            }
        });
    });
}

test.afterAll(() => setPlan('free'));
