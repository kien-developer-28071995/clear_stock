import { api, expect, open, setPlan, settled, test, toasts } from './support/app';

/**
 * When the backend fails. Every screen must say so (never an endless spinner, a blank page or a
 * crash) and offer a retry; every failed save must be announced and lose nothing that was typed.
 */
const PAGES = [
    '/', '/reorder', '/reorder/orders', '/reorder/transfers', '/products', '/products/bundles', '/products/costs',
    '/insights', '/planning', '/planning/budget', '/planning/what-if', '/planning/events', '/suppliers', '/suppliers/from-vendors',
    '/settings', '/data-health', '/plans',
];
/** Always answered: who the shop is (plan, switches) and the page's side notes. */
const KEPT = /\/api\/(shop|setup-guide|client-errors|web-vitals)(\/|\?|$)/;

test.beforeAll(() => setPlan('growth'));

test('every screen says so when its data cannot be loaded, and recovers on retry', async ({ app }) => {
    test.setTimeout(240_000);
    let failing = true;
    // The backend's API only (source files of the app also live in folders named api/).
    await app.route((url) => url.pathname.startsWith('/api/'), (route) =>
        failing && !KEPT.test(route.request().url()) ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"server_error","params":{}}' }) : route.continue(),
    );
    await open(app, '/plans'); // any page: the app is loaded once, then pages change without reloading

    for (const path of PAGES) {
        await app.goto(path);
        const banner = app.locator('s-banner[tone="critical"]').first();
        await expect(banner, `${path} shows an error`).toBeVisible({ timeout: 20_000 });
        await expect(banner.locator('s-button', { hasText: 'Try again' }), `${path} offers a retry`).toHaveCount(1);
        // Closed dialogs keep their own (invisible) loading state.
        await expect.poll(() => app.evaluate(() => [...document.querySelectorAll('#root s-spinner')].filter((e) => !e.closest('s-modal')).length), { message: `${path} is not left loading` }).toBe(0);
    }

    // The product page as well, and the backend coming back.
    await app.goto('/products/1');
    await expect(app.locator('s-banner[tone="critical"]').first()).toBeVisible({ timeout: 20_000 });
    await app.goto('/products');
    await expect(app.locator('s-banner[tone="critical"]').first()).toBeVisible({ timeout: 20_000 });
    failing = false;
    await app.locator('s-banner[tone="critical"] s-button', { hasText: 'Try again' }).first().click();
    await expect(app.locator('s-table-body s-table-row').first()).toBeVisible();
    await expect(app.locator('s-banner[tone="critical"]')).toHaveCount(0);
});

test('a failed save is announced and keeps what was typed', async ({ app }) => {
    const before = (await (async () => { await open(app, '/settings'); return api<{ data: { default_lead_time_days: number } }>(app, '/settings'); })()).data.default_lead_time_days;
    await app.route('**/api/settings', (route) => (route.request().method() === 'PUT' ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"server_error","params":{}}' }) : route.continue()));

    const field = app.getByLabel('Default lead time');
    await field.fill(String(before + 3));
    await expect.poll(() => app.evaluate(() => window.__e2e.saveBar['settings-save-bar'])).toBe(true);
    await app.locator('ui-save-bar#settings-save-bar button').first().evaluate((el: HTMLElement) => el.click());

    await expect.poll(() => toasts(app)).toContainEqual(expect.stringMatching(/went wrong|failed/i));
    await expect(field).toHaveValue(String(before + 3));                                             // nothing typed is lost
    expect(await app.evaluate(() => window.__e2e.saveBar['settings-save-bar'])).toBe(true);           // still unsaved
    expect((await api<{ data: { default_lead_time_days: number } }>(app, '/settings')).data.default_lead_time_days).toBe(before);
});

test('a failed "mark as ordered" keeps the dialog open and says why', async ({ app }) => {
    await open(app, '/reorder');
    await settled(app);
    await app.route('**/api/manual-orders', (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"server_error","params":{}}' }) : route.continue()));
    const dialogOpen = () => app.locator('s-modal#mark-ordered-selected').evaluate((el) => !!el.shadowRoot?.querySelector('dialog[open]'));

    await app.locator('s-button', { hasText: 'Mark as ordered' }).first().click();
    await expect.poll(dialogOpen).toBe(true);
    await app.locator('s-modal#mark-ordered-selected s-button[slot="primary-action"]').click();

    await expect.poll(() => toasts(app)).toContainEqual(expect.stringMatching(/went wrong|failed/i));
    expect(await dialogOpen()).toBe(true);
    await expect(app.locator('s-modal#mark-ordered-selected s-button[slot="primary-action"]')).not.toHaveAttribute('loading');
    expect((await api<{ data: { open: unknown[] } }>(app, '/manual-orders')).data.open).toHaveLength(0);
});

test('bad input is refused at the field it was typed in, in words', async ({ app }) => {
    const settings = (await (async () => { await open(app, '/settings'); return api<{ data: { default_lead_time_days: number } }>(app, '/settings'); })()).data;
    const errorOf = (label: string) => app.locator(`s-number-field[label="${label}"]`).first().evaluate((el: HTMLElement & { error?: string }) => el.error ?? el.getAttribute('error') ?? '');

    // A lead time of 0 days: refused, explained at the field, nothing saved, still unsaved.
    await app.getByLabel('Default lead time').fill('0');
    await app.locator('ui-save-bar#settings-save-bar button').first().evaluate((el: HTMLElement) => el.click());
    await expect.poll(() => errorOf('Default lead time')).not.toBe('');
    expect(await errorOf('Default lead time')).not.toMatch(/validation\.|undefined|\{\{/); // a sentence, not a key or a raw template
    expect((await api<{ data: { default_lead_time_days: number } }>(app, '/settings')).data.default_lead_time_days).toBe(settings.default_lead_time_days);
    expect(await app.evaluate(() => window.__e2e.saveBar['settings-save-bar'])).toBe(true);
    await app.locator('ui-save-bar#settings-save-bar button').nth(1).evaluate((el: HTMLElement) => el.click());
    await expect(app.getByLabel('Default lead time')).toHaveValue(String(settings.default_lead_time_days));

    // A sales event that ends before it starts.
    await open(app, '/planning/events');
    await app.locator('s-button', { hasText: 'Add event' }).first().evaluate((el: HTMLElement) => el.click());
    const modal = app.locator('s-modal#sales-event-modal');
    await modal.getByRole('textbox', { name: 'Name' }).fill('Backwards');
    for (const [label, value] of [['From', '2026-12-10'], ['To', '2026-12-01']] as const) {
        await modal.locator(`s-date-field[label="${label}"]`).evaluate((el: HTMLElement & { value: string }, v) => {
            el.value = v;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
    }
    await modal.locator('s-button[slot="primary-action"]').click();
    await expect.poll(() => modal.locator('s-date-field[label="To"]').evaluate((el: HTMLElement & { error?: string }) => el.error ?? '')).not.toBe('');
    expect((await api<{ data: unknown[] }>(app, '/sales-events')).data.filter((e) => (e as { name: string }).name === 'Backwards')).toHaveLength(0);
    await app.keyboard.press('Escape');
});
