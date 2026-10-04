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
    ['/reorder/orders', 'Reorder'],
    ['/reorder/transfers', 'Reorder'],
    ['/insights', 'Insights'],
    ['/planning', 'Planning'],
    ['/planning/budget', 'Planning'],
    ['/planning/what-if', 'Planning'],
    ['/planning/events', 'Planning'],
    ['/products', 'Products'],
    ['/products/bundles', 'Products'],
    ['/products/costs', 'Products'],
    ['/suppliers', 'Suppliers'],
    ['/suppliers/import', 'Import'],
    ['/suppliers/from-vendors', 'vendors'],
    ['/settings', 'Settings'],
    ['/data-health', 'Data check'],
    ['/plans', 'Plans'],
    // Paths from before the pages were grouped still work.
    ['/orders', 'Reorder'],
    ['/what-if', 'Planning'],
    ['/costs', 'Products'],
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
                ['abc_class', 'avg_daily_sales', 'current_stock', 'days_of_cover', 'excess_units', 'incoming_stock', 'name', 'reorder_date', 'sku', 'status', 'suggested_qty', 'trend_percent', 'variant_id', 'vendor'].sort(),
            );
            await expect(app.locator('s-table-body s-table-row')).toHaveCount(Math.min(list.meta.total, list.meta.per_page));
        });

        test('ABC classes: filter, badge, explanation on the product and summary in insights', async ({ app }) => {
            await open(app, '/products');
            const dashboard = await api<{ data: { abc: { classes: Record<'A' | 'B' | 'C', { count: number }> } } }>(app, '/dashboard');
            const countA = dashboard.data.abc.classes.A.count;
            test.skip(countA === 0, 'dev store has no sales with a price yet (run a full sync)');

            await app.locator('s-press-button', { hasText: 'More filters' }).click();
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

            await open(app, '/insights?tab=products');
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
            if (grown.data.totals.scenario.products > 0 && has.purchase_orders) {
                const download = app.waitForEvent('download');
                await app.locator('s-button', { hasText: 'Export scenario orders (CSV)' }).first().evaluate((el: HTMLElement) => el.click());
                const lines = fs.readFileSync(await (await download).path(), 'utf8').trim().split('\n');
                expect(lines[0]).toContain('Order date');
                expect(lines.length - 1).toBe(grown.data.totals.scenario.products);
            }
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
                await expect(app.locator('s-section[heading="By product"] s-table-body s-table-row')).toHaveCount(Math.min(plan12.data.items.length, 10)); // the rest sits behind "Show all"
            }
            const plan4 = await api<Plan>(app, '/purchase-plan?weeks=4');
            expect(plan4.data.totals.units).toBeLessThanOrEqual(plan12.data.totals.units);
            if (plan12.data.totals.orders > 0) {
                // Every planned order as a CSV line.
                const download = app.waitForEvent('download');
                // A page header action: rendered by the admin, so clicked directly.
                await app.locator('s-button', { hasText: 'Export orders (CSV)' }).first().evaluate((el: HTMLElement) => el.click());
                const lines = fs.readFileSync(await (await download).path(), 'utf8').trim().split('\n');
                expect(lines[0]).toContain('Order date');
                expect(lines).toHaveLength(plan12.data.totals.orders + 1);
            }
            await app.screenshot({ path: `e2e-results/purchase-plan-${plan}.png`, fullPage: true });
        });

        test('purchase plan: order calendar follows the supplier order days', async ({ app }) => {
            if (!has.purchase_plan) return;
            type Plan = { data: { totals: { units: number }; calendar: { date: string; supplier: string | null; units: number }[] } };
            type Suppliers = { data: { id: number; name: string; order_weekdays: number[] | null }[] };
            await open(app, '/purchase-plan');
            const plan = (await api<Plan>(app, '/purchase-plan')).data;
            expect(plan.calendar.reduce((sum, c) => sum + c.units, 0)).toBe(plan.totals.units);
            if (plan.calendar.length > 0) await expect(app.locator('s-section[heading="Order calendar"] s-table-body s-table-row')).toHaveCount(Math.min(plan.calendar.length, 10));

            // Suppliers with order days: every later order falls on one of them.
            const suppliers = (await api<Suppliers>(app, '/suppliers')).data.filter((s) => s.order_weekdays);
            for (const s of suppliers) {
                for (const c of plan.calendar.filter((c) => c.supplier === s.name && c.date > plan.calendar[0].date)) {
                    const iso = ((new Date(`${c.date}T00:00:00Z`).getUTCDay() + 6) % 7) + 1;
                    expect(s.order_weekdays).toContain(iso);
                }
            }
        });

        test('a sales event raises what to order and is explained, then removed', async ({ app }) => {
            type Events = { data: { id: number; name: string }[] };
            type Detail = { data: { explanation_lines: { code: string; params: { name?: string } }[] } };
            const name = `E2E Promo ${plan}`;
            const day = (offset: number) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);

            await open(app, '/events');
            await app.locator('s-button', { hasText: 'Add event' }).first().evaluate((el: HTMLElement) => el.click());
            const modal = app.locator('s-modal#sales-event-modal');
            await modal.getByRole('textbox', { name: 'Name' }).fill(name);
            // Date fields are comboboxes (typed date + picker); the value is taken on blur.
            for (const [label, value] of [['From', day(3)], ['To', day(7)]]) {
                await modal.getByRole('combobox', { name: label, exact: true }).fill(value);
                await modal.getByRole('combobox', { name: label, exact: true }).press('Tab');
            }
            await modal.getByRole('spinbutton', { name: 'Sales change' }).fill('200');
            await modal.locator('s-button[slot="primary-action"]').click();
            const row = app.locator('s-table-row', { hasText: name });
            await expect(row).toContainText('+200%');
            await expect(row).toContainText('Upcoming');

            // The recompute is queued: poll a selling product's explanation.
            await open(app, '/products?status=reorder_now');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            const variantId = Number(app.url().split('/').pop());
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.explanation_lines.some((l) => l.code.startsWith('event_upcoming') && l.params.name === name), { timeout: 30_000 }).toBe(true);
            await app.reload();
            await expect(app.getByText(name).first()).toBeVisible();

            await open(app, '/events');
            await row.locator('s-button', { hasText: 'Delete' }).click();
            await app.locator('s-modal s-button[slot="primary-action"]', { hasText: 'Delete' }).click();
            await expect(row).toHaveCount(0);
            expect((await api<Events>(app, '/sales-events')).data.some((e) => e.name === name)).toBe(false);
        });

        test('products ordered outside Shopify count as on the way until received', async ({ app }) => {
            type Orders = { data: { open: { id: number; variant_id: number; quantity: number }[] } };
            type Detail = { data: { suggested_qty: number; incoming_stock: number } };
            const call = (path: string, method: string, body?: unknown) =>
                app.evaluate(async ({ path, method, body }) => {
                    const r = await fetch(`/api${path}`, { method, headers: { Authorization: `Bearer ${await window.shopify.idToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' }, body: body ? JSON.stringify(body) : undefined });
                    return r.status;
                }, { path, method, body });

            await open(app, '/products?status=reorder_now');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            const variantId = Number(app.url().split('/').pop());
            const before = (await api<Detail>(app, `/forecasts/${variantId}`)).data;
            expect(before.suggested_qty).toBeGreaterThan(0);

            // The product page's "Mark as ordered" (a header action) with the suggested quantity.
            await app.locator('s-button', { hasText: 'Mark as ordered' }).first().evaluate((el: HTMLElement) => el.click());
            const modal = app.locator('s-modal#mark-ordered-product');
            await expect(modal.getByRole('spinbutton', { name: 'Quantity' })).toHaveValue(String(before.suggested_qty));
            await modal.locator('s-button[slot="primary-action"]').click();
            await expect.poll(() => toasts(app)).toContainEqual(expect.stringContaining('marked as ordered'));
            const after = (await api<Detail>(app, `/forecasts/${variantId}`)).data;
            expect(after.suggested_qty).toBe(0);
            expect(after.incoming_stock).toBe(before.incoming_stock + before.suggested_qty);

            await open(app, '/orders');
            const orders = (await api<Orders>(app, '/manual-orders')).data.open.filter((o) => o.variant_id === variantId);
            expect(orders).toHaveLength(1);
            await app.locator('s-table-row', { hasText: String(before.suggested_qty) }).locator('s-button', { hasText: 'Received' }).first().click();
            await expect.poll(async () => (await api<Orders>(app, '/manual-orders')).data.open.some((o) => o.variant_id === variantId)).toBe(false);
            expect((await api<Detail>(app, `/forecasts/${variantId}`)).data.suggested_qty).toBe(before.suggested_qty);

            // Leave nothing open (other tests read the reorder list).
            for (const o of (await api<Orders>(app, '/manual-orders')).data.open) expect(await call(`/manual-orders/${o.id}`, 'PATCH', { status: 'cancelled' })).toBe(200);
        });

        test('unit costs can be entered in the app and feed the money figures', async ({ app }) => {
            type Costs = { data: { counts: { missing: number }; items: { variant_id: number; name: string; app_cost: number | null; cost: number | null }[] } };
            await open(app, '/costs');
            const list = (await api<Costs>(app, '/costs?missing=1')).data;
            const row = list.items[0] ?? (await api<Costs>(app, '/costs')).data.items[0];
            if (list.items.length === 0) await app.getByRole('checkbox', { name: 'Only products without a cost' }).uncheck();

            await app.getByRole('spinbutton', { name: `Your cost for ${row.name}` }).fill('7.25');
            await saveBar(app, 'costs-save-bar');
            await expect.poll(async () => (await api<Costs>(app, '/costs')).data.items.find((i) => i.variant_id === row.variant_id)?.cost).toBe(7.25);
            type Detail = { data: { settings: { cost_override: number | null } } };
            expect((await api<Detail>(app, `/forecasts/${row.variant_id}`)).data.settings.cost_override).toBe(7.25);

            // Put it back: cleared in the product settings.
            await open(app, `/products/${row.variant_id}?tab=settings`);
            await app.locator('s-section[heading="Product settings"]').getByRole('spinbutton', { name: 'Unit cost' }).fill('');
            await saveBar(app, 'product-settings-save-bar');
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${row.variant_id}`)).data.settings.cost_override).toBeNull();
        });

        test('order budget ranks what is due and follows the plan', async ({ app }) => {
            type Budget = { data: { budget: number | null; remaining: number | null; items: { priority: number; in_budget: boolean; cost: number | null }[] } };
            await open(app, '/budget');
            if (!has.purchase_plan) {
                await expect(app.locator('s-banner[heading="Included in Starter"]')).toBeVisible();
                expect(await app.evaluate(async () => (await fetch('/api/budget', { headers: { Authorization: `Bearer ${await window.shopify.idToken()}` } })).status)).toBe(402);
                return;
            }
            await app.getByRole('spinbutton', { name: 'Monthly budget' }).fill('1');
            await saveBar(app, 'budget-save-bar');
            const data = (await api<Budget>(app, '/budget')).data;
            expect(data.budget).toBe(1);
            expect(data.items.map((i) => i.priority)).toEqual(data.items.map((_, n) => n + 1));
            // $1 fits nothing with a cost.
            expect(data.items.filter((i) => i.cost !== null && i.cost > 1).every((i) => !i.in_budget)).toBe(true);
            if (data.items.length > 0) await expect(app.locator('s-table-body s-table-row')).toHaveCount(data.items.length);
            await app.screenshot({ path: `e2e-results/budget-${plan}.png`, fullPage: true });

            await app.getByRole('spinbutton', { name: 'Monthly budget' }).fill('');
            await saveBar(app, 'budget-save-bar');
            expect((await api<Budget>(app, '/budget')).data.budget).toBeNull();
        });

        test('insights show forecast accuracy (or when it starts)', async ({ app }) => {
            type Accuracy = { data: { available: boolean; latest: { products: number } | null } };
            await open(app, '/insights?tab=products');
            const report = (await api<Accuracy>(app, '/accuracy')).data;
            const section = app.locator('s-section[heading="Forecast accuracy"]');
            await expect(section).toBeVisible();
            await expect(section).toContainText(report.available ? 'accurate from' : 'each week we save the forecast');
            await app.screenshot({ path: `e2e-results/accuracy-${plan}.png`, fullPage: true });
        });

        test('a discontinued product gets no order suggestion until it is reordered again', async ({ app }) => {
            type Detail = { data: { status: string; suggested_qty: number; settings: { discontinued: boolean }; explanation_lines: { code: string }[] } };
            await open(app, '/products?status=reorder_now');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            const variantId = Number(app.url().split('/').pop());
            await app.locator('s-press-button', { hasText: 'Settings' }).click();

            await app.getByRole('checkbox', { name: 'Discontinued: stop reordering this product' }).check();
            await saveBar(app, 'product-settings-save-bar');
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.status).toBe('discontinued');
            const detail = (await api<Detail>(app, `/forecasts/${variantId}`)).data;
            expect(detail.suggested_qty).toBe(0);
            expect(detail.explanation_lines.some((l) => l.code.startsWith('discontinued_'))).toBe(true);
            await expect(app.getByText('You stopped reordering this product').first()).toBeVisible();

            await open(app, '/products?status=discontinued');
            await expect(app.locator('s-table-body s-table-row')).toHaveCount(1);

            await open(app, `/products/${variantId}?tab=settings`);
            await app.getByRole('checkbox', { name: 'Discontinued: stop reordering this product' }).uncheck();
            await saveBar(app, 'product-settings-save-bar');
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.settings.discontinued).toBe(false);
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
            await open(app, '/settings?tab=alerts');
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
            await app.locator('s-press-button', { hasText: 'Settings' }).click();
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

        test('forecast profile: the store setting and a product\'s own change the windows used', async ({ app }) => {
            type Detail = { data: { explanation: { profile: { name: string; source: string }; windows: { days: number }[] }; settings: { forecast_profile: string | null } } };
            await open(app, '/products?sort=suggested');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            const variantId = Number(app.url().split('/').pop());
            await app.locator('s-press-button', { hasText: 'Settings' }).click();

            await app.locator('s-select[label="Sales rate for this product"]').locator('select').selectOption('steady').catch(async () => {
                await app.getByRole('combobox', { name: 'Sales rate for this product' }).selectOption('steady');
            });
            await saveBar(app, 'product-settings-save-bar');
            let detail = (await api<Detail>(app, `/forecasts/${variantId}`)).data;
            expect(detail.settings.forecast_profile).toBe('steady');
            expect(detail.explanation.profile).toEqual({ name: 'steady', source: 'variant' });
            expect(detail.explanation.windows.map((w) => w.days)).toEqual([30, 90, 365]);

            await api(app, `/variants/${variantId}/settings`, { method: 'PUT', body: { forecast_profile: null } });
            detail = (await api<Detail>(app, `/forecasts/${variantId}`)).data;
            expect(detail.explanation.profile).toEqual({ name: 'balanced', source: 'shop' });
            expect(detail.explanation.windows.map((w) => w.days)).toEqual([7, 30, 90]);
        });

        test('trend filter lists only products selling clearly faster or slower', async ({ app }) => {
            type Row = { trend_percent: number | null };
            await open(app, '/products');
            const all = (await api<{ data: Row[] }>(app, '/forecasts')).data;
            await app.locator('s-press-button', { hasText: 'More filters' }).click();
            for (const [option, keep] of [['up', (r: Row) => (r.trend_percent ?? 0) >= 25], ['down', (r: Row) => (r.trend_percent ?? 0) <= -25]] as const) {
                await app.getByRole('combobox', { name: 'Trend' }).selectOption(option);
                await expect(app).toHaveURL(new RegExp(`trend=${option}`));
                await settled(app);
                const listed = (await api<{ data: Row[]; meta: { total: number } }>(app, `/forecasts?trend=${option}`));
                expect(listed.data.every(keep)).toBe(true);
                expect(listed.meta.total).toBe(all.filter(keep).length);
                await expect(app.locator('s-table-body s-table-row')).toHaveCount(listed.meta.total);
            }
        });

        test('insights show the inventory value history', async ({ app }) => {
            type History = { data: { latest: { units: number } | null; points: unknown[] } };
            await open(app, '/insights');
            const history = (await api<History>(app, '/stock-history?days=90')).data;
            expect(history.latest).not.toBeNull();   // a forecast run records today's stock
            const section = app.locator('s-section[heading="Inventory value over time"]');
            await expect(section).toBeVisible();
            await expect(section).toContainText('in stock');
            await app.screenshot({ path: `e2e-results/stock-history-${plan}.png`, fullPage: true });
        });

        test('data check lists what to fix in the product data', async ({ app }) => {
            type Health = { data: { checked: number; findings: { code: string; count: number }[] } };
            await open(app, '/settings?tab=general');
            await app.locator('s-link', { hasText: 'Check my product data' }).click();
            await expect(app.locator('s-page[heading="Data check"]')).toBeVisible();
            await settled(app);
            const health = (await api<Health>(app, '/data-health')).data;
            await expect(app.locator('s-section')).toHaveCount(health.findings.length + 1);
            if (health.findings.length === 0) await expect(app.locator('s-page')).toContainText('nothing to fix');
            await app.screenshot({ path: `e2e-results/data-health-${plan}.png`, fullPage: true });
        });

        test('weekly summary can be switched on from Settings on every plan', async ({ app }) => {
            type S = { data: { alerts: { weekly_summary: boolean; email: string | null; weekly_day: number } } };
            await open(app, '/settings?tab=alerts');
            const before = (await api<S>(app, '/settings')).data.alerts;
            const toggle = app.locator('s-switch[label="Email me a summary once a week"]');
            await expect(toggle).not.toHaveAttribute('disabled');
            await api(app, '/settings', { method: 'PUT', body: { alerts: { weekly_summary: true, email: 'owner@example.com' } } });
            expect((await api<S>(app, '/settings')).data.alerts.weekly_summary).toBe(true);
            await api(app, '/settings', { method: 'PUT', body: { alerts: { weekly_summary: before.weekly_summary, email: before.email } } });
        });

        test('Slack webhook and days-left threshold are saved with the alert settings', async ({ app }) => {
            type S = { data: { alerts: { slack_webhook_url: string | null; cover_days: number | null } } };
            await open(app, '/settings?tab=alerts');
            const slack = app.locator('s-url-field[label="Slack webhook URL"]');
            if (has.alerts) await expect(slack).not.toHaveAttribute('disabled');
            else await expect(slack).toHaveAttribute('disabled');

            const bad = await app.evaluate(async () => {
                const res = await fetch('/api/settings', { method: 'PUT', headers: { Authorization: `Bearer ${await window.shopify.idToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ alerts: { slack_webhook_url: 'https://example.com/hook' } }) });
                return res.status;
            });
            expect(bad).toBe(422);
            await api(app, '/settings', { method: 'PUT', body: { alerts: { slack_webhook_url: 'https://hooks.slack.com/services/T0/B0/e2e', cover_days: 30 } } });
            expect((await api<S>(app, '/settings')).data.alerts).toMatchObject({ slack_webhook_url: 'https://hooks.slack.com/services/T0/B0/e2e', cover_days: 30 });
            await api(app, '/settings', { method: 'PUT', body: { alerts: { slack_webhook_url: null, cover_days: null } } });
        });

        test('a partly delivered order keeps the rest on the way', async ({ app }) => {
            type Orders = { data: { open: { id: number; variant_id: number; quantity: number; received_quantity: number; state: string }[] } };
            await open(app, '/orders');
            // A product with a forecast (untracked ones, like gift cards, have none).
            const variant = { id: (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id };
            const incoming = async () => (await api<{ data: { incoming_stock: number } }>(app, `/forecasts/${variant.id}`)).data?.incoming_stock ?? 0;
            const before = await incoming();
            await api(app, '/manual-orders', { method: 'POST', body: { items: [{ variant_id: variant.id, quantity: 40 }], reference: 'E2E-PART' } });
            const order = (await api<Orders>(app, '/manual-orders')).data.open.find((o) => o.variant_id === variant.id && o.quantity === 40)!;

            await open(app, '/orders');
            const row = app.locator('s-table-row', { hasText: 'E2E-PART' }).first();
            await row.locator('s-button', { hasText: 'Part received' }).click();
            await row.getByRole('spinbutton', { name: 'Received so far' }).fill('15');
            await row.locator('s-button', { hasText: 'Save' }).click();
            await expect.poll(async () => (await api<Orders>(app, '/manual-orders')).data.open.find((o) => o.id === order.id)?.received_quantity).toBe(15);
            await expect(row).toContainText('15 received');
            expect(await incoming()).toBe(before + 25);

            await api(app, `/manual-orders/${order.id}`, { method: 'PATCH', body: { status: 'cancelled' } });
            expect(await incoming()).toBe(before);
        });

        test('supplier product code is saved on the product and exported', async ({ app }) => {
            await open(app, '/products');
            await app.locator('s-table-body s-table-row s-link').first().click();
            await settled(app);
            const variantId = Number(app.url().split('/').pop());
            await app.locator('s-press-button', { hasText: 'Settings' }).click();
            await app.locator('s-section[heading="Product settings"]').getByRole('textbox', { name: "Supplier's product code" }).fill('E2E-SUP-1');
            await saveBar(app, 'product-settings-save-bar');
            expect((await api<{ data: { settings: { supplier_sku: string | null } } }>(app, `/forecasts/${variantId}`)).data.settings.supplier_sku).toBe('E2E-SUP-1');
            await api(app, `/variants/${variantId}/settings`, { method: 'PUT', body: { supplier_sku: null } });
        });

        test('the product list exports as CSV with the current filters', async ({ app }) => {
            await open(app, '/products?status=reorder_now');
            const total = (await api<{ meta: { total: number } }>(app, '/forecasts?status=reorder_now')).meta.total;
            // The button sits in the page's title bar, which only the Shopify admin renders: ask the API as the button does.
            const csv = (await app.evaluate(async () => {
                const res = await fetch('/api/forecasts/export?status=reorder_now', { headers: { Authorization: `Bearer ${await window.shopify.idToken()}` } });
                return res.ok ? await res.text() : `HTTP ${res.status}`;
            })).trim().split('\n');
            expect(csv[0]).toContain('Product,SKU,Vendor,Supplier,Status');
            expect(csv).toHaveLength(total + 1);
        });

        test('locations can be left out of the stock the forecast counts', async ({ app }) => {
            type Loc = { id: number; name: string; excluded: boolean };
            await open(app, '/settings');
            const locations = (await api<{ data: Loc[] }>(app, '/settings/locations')).data;
            const section = app.locator('s-section[heading="Locations counted as stock"]');
            test.skip(locations.length < 2, 'dev store has a single location');
            await expect(section.locator('s-checkbox')).toHaveCount(locations.length);

            const target = locations.find((l) => !l.excluded)!;
            await api(app, '/settings/locations', { method: 'PUT', body: { excluded_ids: [target.id] } });
            expect((await api<{ data: Loc[] }>(app, '/settings/locations')).data.find((l) => l.id === target.id)?.excluded).toBe(true);
            await api(app, '/settings/locations', { method: 'PUT', body: { excluded_ids: locations.filter((l) => l.excluded).map((l) => l.id) } });
        });

        test('a saved view applies its filters with one click', async ({ app }) => {
            type View = { id: number; name: string; filters: Record<string, string> };
            await open(app, '/products?status=reorder_now&sort=name');
            await app.locator('s-press-button', { hasText: 'More filters' }).click();
            await app.getByRole('textbox', { name: 'View name' }).fill('E2E due by name');
            await app.locator('s-button', { hasText: 'Save view' }).click();
            await expect.poll(async () => (await api<{ data: View[] }>(app, '/views')).data.find((v) => v.name === 'E2E due by name')?.filters).toEqual({ status: 'reorder_now', sort: 'name' });

            await open(app, '/products');
            await app.locator('s-button', { hasText: 'E2E due by name' }).click();
            await expect(app).toHaveURL(/status=reorder_now/);
            await expect(app).toHaveURL(/sort=name/);

            const view = (await api<{ data: View[] }>(app, '/views')).data.find((v) => v.name === 'E2E due by name')!;
            await api(app, `/views/${view.id}`, { method: 'DELETE' });
        });

        test('insights list what to clear and broken size runs', async ({ app }) => {
            type Clearance = { data: { count: number } };
            await open(app, '/insights?tab=excess');
            const clearance = (await api<Clearance>(app, '/clearance')).data;
            const runs = (await api<{ data: unknown[] }>(app, '/size-runs')).data;
            await expect(app.locator('s-section[heading="What to clear"]')).toHaveCount(clearance.count > 0 ? 1 : 0);
            expect(Array.isArray(runs)).toBe(true); // shown on the Products and accuracy tab
            await app.screenshot({ path: `e2e-results/clearance-${plan}.png`, fullPage: true });
        });

        test('another supplier can be kept for a product and made its supplier', async ({ app }) => {
            type Supplier = { id: number; name: string };
            type Detail = { data: { settings: { supplier_id: number | null; lead_time_override: number | null; cost_override: number | null; supplier_sku: string | null }; alternate_suppliers: { supplier_id: number }[] } };
            await open(app, '/products');
            const suppliers = (await api<{ data: Supplier[] }>(app, '/suppliers')).data;
            test.skip(suppliers.length < 2, 'dev store needs two suppliers');
            const variantId = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id;
            const before = (await api<Detail>(app, `/forecasts/${variantId}`)).data;
            const other = suppliers.find((s) => s.id !== before.settings.supplier_id)!;

            await open(app, `/products/${variantId}?tab=suppliers`);
            const section = app.locator('s-section[heading="Other suppliers"]');
            await section.getByRole('combobox', { name: 'Add a supplier' }).selectOption(String(other.id));
            await section.getByRole('spinbutton', { name: 'Lead time' }).fill('33');
            await section.locator('s-button', { hasText: /^Add$/ }).click();
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.alternate_suppliers.map((a) => a.supplier_id)).toContain(other.id);

            await section.locator('s-button', { hasText: 'Use as supplier' }).first().click();
            await expect.poll(async () => (await api<Detail>(app, `/forecasts/${variantId}`)).data.settings.supplier_id).toBe(other.id);
            expect((await api<Detail>(app, `/forecasts/${variantId}`)).data.settings.lead_time_override).toBe(33);

            // Put the product back as it was.
            await api(app, `/variants/${variantId}/suppliers`, { method: 'PUT', body: { suppliers: [] } });
            await api(app, `/variants/${variantId}/settings`, { method: 'PUT', body: { supplier_id: before.settings.supplier_id, lead_time_override: before.settings.lead_time_override, supplier_sku: before.settings.supplier_sku, cost_override: before.settings.cost_override } });
        });

        test('a sales event can repeat every year', async ({ app }) => {
            type Event = { id: number; name: string; repeats_yearly: boolean };
            await open(app, '/events');
            await api(app, '/sales-events', { method: 'POST', body: { name: 'E2E season', starts_on: '2025-12-01', ends_on: '2025-12-20', multiplier: 2, repeats_yearly: true, applies_to: 'all' } });
            await open(app, '/events');
            await expect(app.locator('s-table-row', { hasText: 'E2E season' }).locator('s-badge', { hasText: 'Every year' })).toBeVisible();
            const event = (await api<{ data: Event[] }>(app, '/sales-events')).data.find((e) => e.name === 'E2E season')!;
            expect(event.repeats_yearly).toBe(true);
            await api(app, `/sales-events/${event.id}`, { method: 'DELETE' });
        });

        test('Shopify purchase orders ask for the permission first and follow the plan', async ({ app }) => {
            await open(app, '/orders');
            const section = app.locator('s-section[heading="Purchase orders in Shopify"]');
            if (has.purchase_orders) {
                await expect(section).toBeVisible();
                await expect(section.locator('s-button', { hasText: 'Show Shopify purchase orders' })).toBeVisible();
            } else {
                await expect(section).toHaveCount(0);
            }
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
            await open(app, '/settings?tab=general');
            await app.getByRole('combobox', { name: 'Language' }).selectOption('vi');
            await saveBar(app, 'settings-save-bar');
            await expect(app.locator('s-page[heading="Cài đặt"]')).toBeVisible();
            await open(app, '/products');
            await expect(app.locator('s-page[heading="Sản phẩm"]')).toBeVisible();

            await open(app, '/settings?tab=general');
            await app.getByRole('combobox', { name: 'Ngôn ngữ' }).selectOption({ index: 0 });
            await saveBar(app, 'settings-save-bar');
            await expect(app.locator('s-page[heading="Settings"]')).toBeVisible();
        });

        test('every other language renders the app and the explanations', async ({ app }) => {
            const languages: [string, string, string, string][] = [
                ['es', 'Configuración', 'Productos', 'Idioma de la app'],
                ['de', 'Einstellungen', 'Produkte', 'App-Sprache'],
                ['fr', 'Paramètres', 'Produits', "Langue de l'application"],
                ['pt', 'Configurações', 'Produtos', 'Idioma do app'],
            ];
            let label = 'App language';
            for (const [locale, settings, products, languageLabel] of languages) {
                await open(app, '/settings?tab=general');
                await app.getByRole('combobox', { name: label }).selectOption(locale);
                await saveBar(app, 'settings-save-bar');
                await expect(app.locator(`s-page[heading="${settings}"]`)).toBeVisible();
                await open(app, '/products?status=reorder_now');
                await expect(app.locator(`s-page[heading="${products}"]`)).toBeVisible();
                // An explanation sentence, not a raw code.
                await app.locator('s-table-body s-table-row s-link').first().click();
                await settled(app);
                await expect(app.locator('body')).not.toContainText('explanation.');
                label = languageLabel;
            }
            await open(app, '/settings?tab=general');
            await app.getByRole('combobox', { name: label }).selectOption({ index: 0 });
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
            // Order days: Monday and Thursday.
            await modal.getByRole('checkbox', { name: 'Mon' }).check();
            await modal.getByRole('checkbox', { name: 'Thu' }).check();
            await modal.locator('s-button[slot="primary-action"]').click();
            await expect(row).toContainText('11 days');
            await expect(row).toContainText('Orders on Mon, Thu');
            type Suppliers = { data: { name: string; order_weekdays: number[] | null }[] };
            expect((await api<Suppliers>(app, '/suppliers')).data.find((s) => s.name === name)?.order_weekdays).toEqual([1, 4]);

            // Emailing a purchase order to a supplier (with an email address) is Starter and up.
            const email = row.locator('s-button', { hasText: /email/i });
            if (has.supplier_emails) await expect(email).not.toHaveAttribute('disabled');
            else await expect(email).toHaveAttribute('disabled');

            await row.getByRole('button', { name: /More actions/ }).click();
            await row.getByRole('menuitem', { name: 'Delete' }).click();
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
            await open(app, '/settings?tab=alerts');
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
            await app.locator('s-press-button', { hasText: 'More filters' }).click();
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
