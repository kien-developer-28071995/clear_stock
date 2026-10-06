import { expect, test } from '@playwright/test';
import { sessionToken, settled, stubAppBridge } from './support/app';

/**
 * The frontend as it ships: the built static site (dist/) on one origin, the backend API on
 * another. Proves the split works in a real browser: every call crosses origins (CORS preflight,
 * bearer token, no cookies), deep links load, and a download can read its file name.
 * Run with `make e2e-split` (builds, serves dist on :4173, API on :8080). Skipped otherwise.
 */
const FRONTEND = process.env.E2E_SPLIT_URL;
const API = process.env.E2E_SPLIT_API ?? 'http://localhost:8080';
test.skip(!FRONTEND, 'run with make e2e-split');

test('the built frontend on its own origin works against the API on another', async ({ page }) => {
    await page.addInitScript(stubAppBridge, { token: sessionToken(), locale: 'en' });
    // App Bridge only works inside the admin: the stub above stands in for it.
    await page.route('https://cdn.shopify.com/shopifycloud/app-bridge.js', (route) => route.fulfill({ contentType: 'text/javascript', body: '' }));
    const calls: { url: string; status: number }[] = [];
    page.on('response', (r) => r.url().includes('/api/') && calls.push({ url: r.url(), status: r.status() }));
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.goto(`${FRONTEND}/`);
    await expect(page.locator('s-page').first()).toBeVisible();
    await settled(page);
    // The page came from the frontend alone; its data from the backend, on another origin.
    expect(new URL(page.url()).origin).toBe(new URL(FRONTEND!).origin);
    expect(new URL(API).origin).not.toBe(new URL(FRONTEND!).origin);
    expect(calls.length).toBeGreaterThan(0);
    expect(calls.filter((c) => !c.url.startsWith(`${API}/api/`))).toEqual([]);
    expect(calls.filter((c) => c.status >= 400)).toEqual([]);

    // A deep link is answered by the frontend's own server (no backend involved), then loads data.
    await page.goto(`${FRONTEND}/products?status=reorder_now`);
    await expect(page.locator('s-table-body s-table-row').first()).toBeVisible();

    // A write (preflighted PUT) and reading it back.
    const saved = await page.evaluate(async (api) => {
        const headers = { Authorization: `Bearer ${await window.shopify.idToken()}`, Accept: 'application/json', 'Content-Type': 'application/json' };
        const before = (await (await fetch(`${api}/api/settings`, { headers })).json()).data.default_lead_time_days as number;
        const put = await fetch(`${api}/api/settings`, { method: 'PUT', headers, body: JSON.stringify({ default_lead_time_days: before }) });
        return { status: put.status, same: (await put.json()).data.default_lead_time_days === before };
    }, API);
    expect(saved).toEqual({ status: 200, same: true });

    // A download: the file name comes from a response header the browser must be allowed to read
    // (the export button sits in the title bar, which only the Shopify admin renders: fetch as it does).
    const file = await page.evaluate(async (api) => {
        const res = await fetch(`${api}/api/forecasts/export`, { headers: { Authorization: `Bearer ${await window.shopify.idToken()}` } });
        return { status: res.status, disposition: res.headers.get('Content-Disposition'), firstLine: (await res.text()).split('\n')[0] };
    }, API);
    expect(file.status).toBe(200);
    expect(file.disposition).toMatch(/filename=.*\.csv/);
    expect(file.firstLine).toContain('Product,SKU');

    expect(errors).toEqual([]);
});
