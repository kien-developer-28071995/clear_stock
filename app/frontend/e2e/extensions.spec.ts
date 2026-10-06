import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { test as base, expect, type Page } from '@playwright/test';
import { artisan, php } from './support/app';

/**
 * Shopify admin extensions (extensions/): the real bundles (`npm run extensions:bundle` at the repo root), run in Chromium against the real
 * API. What Shopify's extension host provides is stubbed: the `shopify` global (data, i18n
 * with the extension's locale files, close) and the ID token it adds to relative fetches.
 * Rendering by Shopify's own components isn't covered (they only exist in the admin).
 */

const ROOT = path.resolve(import.meta.dirname, '../../..');
const EXT = path.join(ROOT, 'extensions');
const ORIGIN = 'http://localhost:8080';

type Locale = 'en' | 'vi';

function bundle(file: string): string {
    return fs.readFileSync(path.join(EXT, 'dist', file), 'utf8');
}

function locale(extension: string, lang: Locale): unknown {
    return JSON.parse(fs.readFileSync(path.join(EXT, extension, 'locales', lang === 'en' ? 'en.default.json' : `${lang}.json`), 'utf8'));
}

/** The extension host: `shopify` global and ID token on relative API calls (as Shopify does). */
function host({ token, selected, messages, lang }: { token: string; selected: string[]; messages: unknown; lang: string }) {
    const lookup = (key: string): unknown => key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], messages);
    const interpolate = (text: string, options: Record<string, unknown>) => text.replace(/\{\{(\w+)\}\}/g, (_, name) => String(options[name] ?? ''));
    const w = window as unknown as Record<string, unknown>;
    w.__closed = false;
    w.shopify = {
        data: { selected: selected.map((id) => ({ id })) },
        close: () => (w.__closed = true),
        i18n: {
            translate: (key: string, options: Record<string, unknown> = {}) => {
                let value = lookup(key);
                if (value && typeof value === 'object' && typeof options.count === 'number') {
                    const forms = value as Record<string, string>;
                    value = forms[new Intl.PluralRules(lang).select(options.count)] ?? forms.other;
                }
                return typeof value === 'string' ? interpolate(value, options) : key;
            },
            formatNumber: (n: number, o?: Intl.NumberFormatOptions) => new Intl.NumberFormat(lang, o).format(n),
            formatDate: (d: Date, o?: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(lang, o).format(d),
        },
    };
    const realFetch = window.fetch.bind(window);
    window.fetch = (input: RequestInfo | URL, init: RequestInit = {}) => {
        const url = String(input);
        if (url.startsWith('api/')) {
            return realFetch(`/${url}`, { ...init, headers: { ...(init.headers as Record<string, string>), Authorization: `Bearer ${token}` } });
        }
        return realFetch(input, init);
    };
}

/** Opens an extension bundle in a blank page with the stubbed host. */
async function mount(page: Page, file: string, extension: string, selected: string[], lang: Locale = 'en') {
    const token = artisan('dev:session-token', '--ttl=3600').split('\n').filter(Boolean).at(-1)!;
    await page.addInitScript(host, { token, selected, messages: locale(extension, lang), lang });
    await page.route(`${ORIGIN}/__ext/**`, (route) =>
        route.request().url().endsWith('.js')
            ? route.fulfill({ contentType: 'text/javascript', body: bundle(file) })
            : route.fulfill({ contentType: 'text/html', body: `<!DOCTYPE html><html><body><script type="module">import run from '/__ext/extension.js'; run();</script></body></html>` }),
    );
    await page.goto(`${ORIGIN}/__ext/index.html`);
}

const test = base.extend<{ errors: string[] }>({
    errors: async ({ page }, use) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await use(errors);
        expect(errors, 'no uncaught errors in the extension').toEqual([]);
    },
});

test.beforeAll(() => {
    execFileSync('npm', ['run', 'extensions:bundle', '--silent'], { cwd: ROOT });
});

/** A synced product whose (first) variant has to be reordered now. */
function productToReorder(): { productId: number; variantId: number } {
    return php(
        '$f = App\\Models\\Forecast::whereNull("location_id")->where("reorder_date", "<=", now()->toDateString())->where("avg_daily_sales", ">", 0)->where("current_stock", ">", 0)->with("variant")->first(); echo json_encode(["productId" => $f->variant->shopify_product_id, "variantId" => $f->variant_id]);',
    );
}

test.describe('product page block', () => {
    test('shows the forecast, the reasoning and a link into the app', async ({ page, errors }) => {
        const { productId, variantId } = productToReorder();
        await mount(page, 'product-forecast-block/src/ProductForecastBlock.js', 'product-forecast-block', [`gid://shopify/Product/${productId}`]);

        await expect(page.locator('s-admin-block[heading="Stock forecast"]')).toBeVisible();
        await expect(page.locator('s-badge')).toContainText('Reorder now');
        await expect(page.getByText('Why these numbers')).toBeVisible();
        await expect(page.locator('s-list-item').first()).toContainText(/Sells .*\/day/);
        await expect(page.locator(`s-link[href="app:products/${variantId}"]`).last()).toContainText('Open in Clear Stock');
        expect(await page.locator('s-admin-block').getAttribute('collapsedsummary')).toMatch(/^Reorder now · order \d+ by /);
        void errors;
    });

    test('speaks the admin language', async ({ page, errors }) => {
        const { productId } = productToReorder();
        await mount(page, 'product-forecast-block/src/ProductForecastBlock.js', 'product-forecast-block', [`gid://shopify/Product/${productId}`], 'vi');

        await expect(page.locator('s-badge')).toContainText('Cần đặt ngay');
        await expect(page.locator('s-table-header', { hasText: 'Đặt trước' })).toHaveCount(1);
        await expect(page.locator('s-list-item').first()).toContainText(/Bán .*\/ngày/);
        void errors;
    });

    test('says when the product is not in the app yet', async ({ page, errors }) => {
        await mount(page, 'product-forecast-block/src/ProductForecastBlock.js', 'product-forecast-block', ['gid://shopify/Product/1']);
        await expect(page.getByText("This product isn't in Clear Stock yet")).toBeVisible();
        void errors;
    });
});

test.describe('product list bulk action', () => {
    test('sets the lead time of every variant of the selected products', async ({ page, errors }) => {
        const { productId, variantId } = productToReorder();
        const before = php<{ lead: number | null }>(`echo json_encode(["lead" => App\\Models\\Variant::find(${variantId})->lead_time_override]);`).lead;
        await mount(page, 'product-settings-action/src/ProductSettingsAction.js', 'product-settings-action', [`gid://shopify/Product/${productId}`]);

        await expect(page.getByText('Applies to every variant of the selected product')).toBeVisible();
        // Nothing chosen: nothing is sent.
        await page.locator('s-button[slot="primary-action"]').click();
        await expect(page.locator('s-banner[tone="critical"]')).toContainText('Choose a supplier, a status or enter a number first.');

        try {
            await page.locator('s-number-field[label="Lead time (days)"]').evaluate((el: HTMLElement & { value: string }) => {
                el.value = '30';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
            await page.locator('s-button[slot="primary-action"]').click();
            await expect(page.locator('s-banner[tone="success"]')).toContainText(/Updated \d+ variants?\. Forecasts are being recalculated\./);
            expect(php<{ lead: number | null }>(`echo json_encode(["lead" => App\\Models\\Variant::find(${variantId})->lead_time_override]);`).lead).toBe(30);

            await page.locator('s-button[slot="primary-action"]').click();
            await expect.poll(() => page.evaluate(() => (window as unknown as { __closed: boolean }).__closed)).toBe(true);
        } finally {
            php(`App\\Models\\Variant::where("shopify_product_id", ${productId})->update(["lead_time_override" => ${before ?? 'null'}]); echo json_encode(true);`);
            artisan('forecast:run');
        }
        void errors;
    });
});

test.describe('variant page block', () => {
    test('shows the forecast of that one variant', async ({ page, errors }) => {
        const { variantId } = productToReorder();
        const shopifyVariantId = php<{ id: number }>(`echo json_encode(["id" => App\\Models\\Variant::find(${variantId})->shopify_variant_id]);`).id;
        await mount(page, 'product-forecast-block/src/ProductForecastBlock.js', 'product-forecast-block', [`gid://shopify/ProductVariant/${shopifyVariantId}`]);

        await expect(page.locator('s-admin-block[heading="Stock forecast"]')).toBeVisible();
        await expect(page.locator('s-table-body s-table-row')).toHaveCount(1);
        await expect(page.locator('s-badge')).toContainText('Reorder now');
        await expect(page.locator(`s-link[href="app:products/${variantId}"]`).last()).toContainText('Open in Clear Stock');
        void errors;
    });
});

test.describe('product page: mark as ordered', () => {
    test('records the suggested quantity as on the way', async ({ page, errors }) => {
        const { productId, variantId } = productToReorder();
        const suggested = php<{ qty: number }>(`echo json_encode(["qty" => App\\Models\\Forecast::where("variant_id", ${variantId})->whereNull("location_id")->value("suggested_qty")]);`).qty;
        await mount(page, 'product-order-action/src/ProductOrderAction.js', 'product-order-action', [`gid://shopify/Product/${productId}`]);

        await expect(page.locator('s-admin-action[heading="Mark as ordered"]')).toBeVisible();
        const field = page.locator('s-number-field').first();
        // Shopify's components only exist in the admin: here the value is the element's attribute.
        await expect(field).toHaveAttribute('value', String(suggested));
        try {
            await page.locator('s-button[slot="primary-action"]').click();
            await expect(page.locator('s-banner[tone="success"]')).toContainText(/marked as ordered/);
            const open = php<{ qty: number | null }>(`echo json_encode(["qty" => App\\Models\\ManualOrder::where("variant_id", ${variantId})->where("status", "open")->latest("id")->value("quantity")]);`).qty;
            expect(open).toBe(suggested);
            await expect(page.locator('s-link[href="app:orders"]')).toBeVisible();
        } finally {
            php(`App\\Models\\ManualOrder::where("variant_id", ${variantId})->where("status", "open")->update(["status" => "cancelled", "closed_at" => now()]); echo json_encode(true);`);
            artisan('forecast:run');
        }
        void errors;
    });
});

test.describe('product page: reorder settings', () => {
    test('sets pack size and discontinued for the product', async ({ page, errors }) => {
        const { productId, variantId } = productToReorder();
        await mount(page, 'product-settings-action/src/ProductSettingsAction.js', 'product-settings-action', [`gid://shopify/Product/${productId}`]);
        await expect(page.locator('s-admin-action[heading="Reorder settings"]')).toBeVisible();
        try {
            await page.locator('s-number-field[label="Pack size"]').evaluate((el: HTMLElement & { value: string }) => {
                el.value = '12';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
            await page.locator('s-select[label="Discontinued"]').evaluate((el: HTMLElement & { value: string }) => {
                el.value = 'yes';
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
            await page.locator('s-button[slot="primary-action"]').click();
            await expect(page.locator('s-banner[tone="success"]')).toBeVisible();
            const v = php<{ pack: number | null; discontinued: boolean }>(`$v = App\\Models\\Variant::find(${variantId}); echo json_encode(["pack" => $v->pack_size, "discontinued" => $v->discontinued]);`);
            expect(v).toEqual({ pack: 12, discontinued: true });
        } finally {
            php(`App\\Models\\Variant::where("shopify_product_id", ${productId})->update(["pack_size" => null, "discontinued" => false]); echo json_encode(true);`);
            artisan('forecast:run');
        }
        void errors;
    });
});
