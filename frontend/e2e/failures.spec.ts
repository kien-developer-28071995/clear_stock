import { api, expect, open, php, setPlan, settled, test, toasts } from './support/app';

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

test('a failed snooze keeps the dialog open and the list as it was; a failed "bring back" says so', async ({ app }) => {
    type Dash = { data: { actions: Record<string, { variant_id: number }[]>; snoozed: { variant_id: number }[] } };
    const dash = async () => (await api<Dash>(app, '/dashboard')).data;
    const dialogOpen = () => app.locator('s-modal#snooze-selected').evaluate((el) => !!el.shadowRoot?.querySelector('dialog[open]'));
    let failing = true;
    await app.route('**/api/snooze', (route) => (failing ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"server_error","params":{}}' }) : route.continue()));
    await open(app, '/reorder');
    const before = await dash();

    try {
        await app.locator('s-button', { hasText: /^Snooze$/ }).first().click();
        await expect.poll(dialogOpen).toBe(true);
        const confirm = app.locator('s-modal#snooze-selected s-button[slot="primary-action"]');
        await confirm.click();
        await expect.poll(() => toasts(app)).toContainEqual(expect.stringMatching(/went wrong|failed/i));
        expect(await dialogOpen()).toBe(true);
        await expect(confirm).not.toHaveAttribute('loading');
        expect((await dash()).snoozed).toEqual([]);

        // The backend is back: the same dialog works, and a double click snoozes once.
        failing = false;
        await confirm.dblclick();
        await expect.poll(() => toasts(app)).toContainEqual(expect.stringMatching(/snoozed until/));
        await expect.poll(dialogOpen).toBe(false);
        const snoozed = (await dash()).snoozed.map((s) => s.variant_id);
        expect(snoozed.length).toBeGreaterThan(0);
        expect((await toasts(app)).filter((t) => /snoozed until/.test(t))).toHaveLength(1);

        // "Bring back" fails: said so, and the product stays where it is.
        failing = true;
        await app.locator('s-button', { hasText: 'Bring back' }).first().click();
        await expect.poll(async () => (await toasts(app)).filter((t) => /went wrong|failed/i.test(t)).length).toBe(2);
        expect((await dash()).snoozed.map((s) => s.variant_id)).toEqual(snoozed);
        await expect(app.locator('s-button', { hasText: 'Bring back' })).toHaveCount(snoozed.length);
    } finally {
        failing = false;
        const still = (await dash()).snoozed.map((s) => s.variant_id);
        if (still.length > 0) await api(app, '/snooze', { method: 'POST', body: { variant_ids: still, days: null } });
    }
    expect(Object.values((await dash()).actions).flat().length).toBe(Object.values(before.actions).flat().length);
});

test('feedback that cannot be sent is kept in the box, and a wrong reply address is refused at its field', async ({ app }) => {
    let status = 500;
    await app.route('**/api/feedback', (route) =>
        status === 200 ? route.continue() : route.fulfill({ status, contentType: 'application/json', body: status === 422 ? '{"code":"validation_failed","params":{},"errors":{"email":[{"code":"email","params":{}}]}}' : '{"code":"server_error","params":{}}' }),
    );
    await open(app, '/settings?tab=general');
    const modal = app.locator('s-modal#feedback-modal');
    const dialogOpen = () => modal.evaluate((el) => !!el.shadowRoot?.querySelector('dialog[open]'));
    const message = modal.getByRole('textbox', { name: 'Your message' });
    const send = modal.locator('s-button[slot="primary-action"]');

    await app.locator('s-button', { hasText: 'Send feedback' }).click();
    await expect.poll(dialogOpen).toBe(true);
    await message.fill('The purchase plan is hard to read on my phone.');
    await send.click();
    await expect.poll(() => toasts(app)).toContainEqual(expect.stringMatching(/went wrong|failed/i));
    expect(await dialogOpen()).toBe(true);
    await expect(message).toHaveValue('The purchase plan is hard to read on my phone.');
    await expect(send).not.toHaveAttribute('loading');

    status = 422;
    await send.click();
    const emailError = () => modal.locator('s-email-field').evaluate((el: HTMLElement & { error?: string }) => el.error ?? '');
    await expect.poll(emailError).not.toBe('');
    expect(await emailError()).not.toMatch(/validation\.|undefined|\{\{/);
    expect(await dialogOpen()).toBe(true);
    expect(await toasts(app)).not.toContainEqual('Feedback sent. Thank you!');
    await app.keyboard.press('Escape');
});

test('the history of a product says so when it cannot be loaded, and loads on retry', async ({ app }) => {
    let failing = true;
    await app.route(/\/api\/forecasts\/\d+\/changes/, (route) => (failing ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"server_error","params":{}}' }) : route.continue()));
    await open(app, '/');
    const id = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id;
    await open(app, `/products/${id}?tab=history`);

    const section = app.locator('s-section[heading="Changes to this product"]');
    await expect(section.locator('s-banner[tone="critical"]')).toBeVisible();
    await expect(section.locator('s-spinner')).toHaveCount(0);
    // The rest of the product page is untouched by it.
    await app.locator('s-press-button', { hasText: 'Forecast' }).click();
    await expect(app.locator('s-section[heading="Why these numbers?"]')).toBeVisible();
    await app.locator('s-press-button', { hasText: 'History' }).click();
    failing = false;
    await section.locator('s-button', { hasText: 'Try again' }).click();
    await expect(section.locator('s-banner[tone="critical"]')).toHaveCount(0);
    await expect(section).toContainText(/No changes yet|Change/);
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

test('a mangled address never breaks a screen', async ({ app }) => {
    test.setTimeout(180_000);
    await open(app, '/');
    const variant = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id;
    const cases: [string, RegExp | null][] = [
        // [address, text that must be on the page (null: any page of the app)]
        ['/products?status=nonsense&sort=upside-down&page=-3&abc=Q&trend=sideways&location_id=x', null],
        ['/products?page=99999', null],
        ['/products?search=' + encodeURIComponent('<script>alert(1)</script>%_\\'), null],
        ['/products/not-a-number', /doesn't exist|couldn't load|not found/i],
        ['/products/999999999', /doesn't exist|couldn't load|not found/i],
        [`/products/${variant}?tab=nope`, null],
        ['/insights?tab=<b>', null],
        ['/settings?tab=../../etc', null],
        ['/planning/what-if?growth=abc&horizon=7.5&supplier_id=x&abc=Z', null],
        ['/planning?weeks=0&supplier_id=-1&vendor=' + 'v'.repeat(3000), null],
        ['/reorder?vendor=%00%FF', null],
        ['/no/such/page', /doesn't exist/i],
        ['/products/costs?missing=perhaps&search=' + 'x'.repeat(500), null],
    ];
    for (const [path, text] of cases) {
        await app.goto(path);
        await expect(app.locator('s-page').first(), `${path} renders a page`).toBeVisible();
        await settled(app);
        if (text) await expect(app.getByText(text).first(), path).toBeVisible();
        // Nothing typed into the address is ever run or shown as markup.
        expect(await app.evaluate(() => document.querySelectorAll('#root script, #root img[onerror], #root b').length), path).toBe(0);
    }
});

test('an impatient double click sends one request', async ({ app }) => {
    await open(app, '/reorder');
    await settled(app);
    let posts = 0;
    await app.route((url) => url.pathname === '/api/manual-orders', async (route) => {
        if (route.request().method() === 'POST') {
            posts++;
            await new Promise((r) => setTimeout(r, 600)); // a slow server, so the second click lands while the first is in flight
        }
        await route.continue();
    });
    await app.locator('s-button', { hasText: 'Mark as ordered' }).first().click();
    const confirm = app.locator('s-modal#mark-ordered-selected s-button[slot="primary-action"]');
    await confirm.click();
    await confirm.evaluate((el: HTMLElement) => el.click());
    await confirm.evaluate((el: HTMLElement) => el.click());
    await expect.poll(() => toasts(app)).toContainEqual(expect.stringContaining('marked as ordered'));
    expect(posts).toBe(1);

    // And if two identical requests do reach the server in the same moment, it records one.
    const variantId = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts?per_page=50')).data.at(-1)!.variant_id;
    await app.evaluate(async (variant_id) => {
        const headers = { Authorization: `Bearer ${await window.shopify.idToken()}`, 'Content-Type': 'application/json', Accept: 'application/json' };
        const send = () => fetch('/api/manual-orders', { method: 'POST', headers, body: JSON.stringify({ items: [{ variant_id, quantity: 7 }], reference: 'E2E-TWICE' }) });
        await Promise.all([send(), send(), send()]);
    }, variantId);
    const orders = (await api<{ data: { open: { id: number; variant_id: number }[] } }>(app, '/manual-orders')).data.open;
    expect(new Set(orders.map((o) => o.variant_id)).size, 'one order per product').toBe(orders.length);
    for (const o of orders) {
        await app.evaluate(async (id) => {
            await fetch(`/api/manual-orders/${id}`, { method: 'PATCH', headers: { Authorization: `Bearer ${await window.shopify.idToken()}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ status: 'cancelled' }) });
        }, o.id);
    }
});

test('a product with an impossible name breaks no screen, on a desktop or a phone', async ({ app }) => {
    test.setTimeout(240_000);
    await open(app, '/');
    const row = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts?status=reorder_now')).data[0];
    const original = php<{ product_title: string; title: string; sku: string | null; vendor: string | null }>(
        `echo json_encode(App\\Models\\Variant::withoutGlobalScopes()->find(${row.variant_id})->only(['product_title', 'title', 'sku', 'vendor']));`,
    );
    const evil = `<img src=x onerror="window.__pwned=1"><script>window.__pwned=1</script> שלום 😀 ${'Supercalifragilistic'.repeat(4)} ${'wide '.repeat(60)}`;
    const set = (v: typeof original) =>
        php(`$v = App\\Models\\Variant::withoutGlobalScopes()->find(${row.variant_id}); $v->forceFill(json_decode(base64_decode('${Buffer.from(JSON.stringify(v)).toString('base64')}'), true))->save(); App\\Support\\CacheVersion::bumpCatalog($v->shop_id); echo json_encode(true);`);
    // As long as the sync lets them be (it cuts every name at the column's 255 characters).
    set({ product_title: evil.slice(0, 255), title: evil.slice(0, 255), sku: 'SKU-' + 'X'.repeat(251), vendor: 'V'.repeat(255) });

    try {
        for (const width of [1280, 390]) {
            await app.setViewportSize({ width, height: 844 });
            for (const path of ['/', '/reorder', '/products', `/products/${row.variant_id}`, `/products/${row.variant_id}?tab=settings`, '/insights', '/planning', '/planning/budget', '/planning/what-if', '/data-health', '/products/costs']) {
                await app.goto(path);
                await expect(app.locator('s-page').first(), path).toBeVisible();
                await settled(app);
                // Shown as text, never run or built into the page.
                expect(await app.evaluate(() => (window as unknown as { __pwned?: number }).__pwned), `${path} ran markup from a product name`).toBeUndefined();
                expect(await app.evaluate(() => document.querySelectorAll('#root img[onerror], #root script').length), path).toBe(0);
                // And the page still fits its width (3px: the test harness's own menu row).
                const overflow = await app.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                expect(overflow, `${path} at ${width}px is wider than the screen by ${overflow}px`).toBeLessThanOrEqual(3);
            }
        }
    } finally {
        set(original);
        await app.setViewportSize({ width: 1280, height: 720 });
    }
});
