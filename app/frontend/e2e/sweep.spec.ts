import type { Page } from '@playwright/test';
import { api, expect, php, setPlan, settled, test, type PlanKey } from './support/app';

/**
 * A sweep over every screen and tab, on every plan, in every language and on a phone-sized
 * screen. It does nothing on the pages; it looks for what no feature test asserts: an error in
 * the console, an API call that fails, a translation key or "undefined" / "NaN" shown as text,
 * a page wider than its screen. Slow (several hundred page loads): `make e2e-sweep`.
 */
test.skip(!process.env.E2E_SWEEP, 'run with make e2e-sweep');

const LOCALES = ['en', 'vi', 'es', 'de', 'fr', 'pt'];

/** Text that is never meant for a merchant. */
const BROKEN = [
    /\bundefined\b/, /\bNaN\b/, /Invalid Date/, /\[object Object\]/, /\{\{\s*\w+\s*\}\}/,
    // A translation key shown instead of its text ("snooze.action"): a known namespace, a dot, a lowercase key.
    // (Two sentences that meet without a space, "…products.Each…", are not one: the second starts with a capital.)
    /(^|\s)(common|nav|errors|status|table|home|actions|product|products|settings|suppliers|plans|insights|orders|costs|budget|snooze|projection|history|feedback|sample|tabs|sections|explanation|apiErrors|validation)\.[a-z][A-Za-z_]*(\.[A-Za-z_]+)*(\s|$)/,
];

/** Problems seen while the page was used: console errors and failed API calls, tagged with the page. */
function watch(app: Page): { problems: string[]; at: (where: string) => void } {
    const problems: string[] = [];
    let where = '';
    app.on('console', (m) => {
        // The browser's own note about a failed request: the response check names it.
        if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) problems.push(`${where}: console error: ${m.text().slice(0, 200)}`);
    });
    app.on('response', (r) => {
        const path = new URL(r.url()).pathname;
        // Also 402: a page must show its upgrade prompt without asking for what the plan lacks.
        if (path.startsWith('/api/') && r.status() >= 400) problems.push(`${where}: ${r.status()} ${r.request().method()} ${path}`);
    });

    return { problems, at: (w) => (where = w) };
}

/** Opens each page and adds what is wrong on it to `problems`. */
async function scan(app: Page, paths: string[], width: number, problems: string[], at: (where: string) => void): Promise<void> {
    for (const path of paths) {
        at(path);
        await app.goto(path);
        await expect(app.locator('s-page').first(), path).toBeVisible({ timeout: 20_000 });
        await settled(app);
        await app.waitForTimeout(150);

        // Everything a merchant can read: text, and the labels Polaris components take as attributes.
        const text = await app.evaluate(() => {
            const root = document.getElementById('root')!;
            const attrs = ['heading', 'label', 'details', 'placeholder', 'accessibilityLabel', 'error'];
            const fromAttrs = [...root.querySelectorAll('*')].flatMap((el) => attrs.map((a) => el.getAttribute(a) ?? '')).filter(Boolean);
            return [...root.innerText.split('\n'), ...fromAttrs].map((s) => s.trim()).filter(Boolean);
        });
        for (const line of text) {
            const found = BROKEN.map((re) => re.exec(line)).find(Boolean);
            if (found) problems.push(`${path}: shows "${found[0].trim()}" in "${line.slice(Math.max(0, found.index - 40), found.index + 60)}"`);
        }
        const overflow = await app.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        if (overflow > 3) problems.push(`${path}: ${overflow}px wider than a ${width}px screen`);
        // A page that is nothing but its title is a page that failed quietly.
        const empty = await app.evaluate(() => document.querySelector('#root s-page')!.children.length === 0);
        if (empty) problems.push(`${path}: empty page`);
    }
}

function routes(variant: number): string[] {
    return [
        '/', '/reorder', '/reorder/orders', '/reorder/transfers',
        '/products', '/products?status=reorder_now', '/products?status=slow', '/products/bundles', '/products/costs',
        `/products/${variant}`, `/products/${variant}?tab=settings`, `/products/${variant}?tab=suppliers`, `/products/${variant}?tab=history`,
        '/insights', '/insights?tab=excess', '/insights?tab=products',
        '/planning', '/planning/budget', '/planning/what-if', '/planning/events',
        '/suppliers', '/suppliers/import', '/suppliers/from-vendors',
        '/settings', '/settings?tab=alerts', '/settings?tab=general', '/data-health', '/plans', '/no-such-page',
    ];
}

const setLocale = (locale: string | null) => php(`App\\Models\\Shop::first()->update(["locale" => ${locale ? `"${locale}"` : 'null'}]); echo json_encode(true);`);

for (const plan of ['free', 'starter', 'growth'] as PlanKey[]) {
    // Every language once (on the plan that shows the most), English on the others; each also on a phone.
    const runs = (plan === 'growth' ? LOCALES : ['en']).flatMap((locale) => (locale === 'en' ? [1280, 390] : [1280]).map((width) => ({ locale, width })));

    for (const { locale, width } of runs) {
        test(`${plan} · ${locale} · ${width}px: no screen shows an error, a raw key or a broken number`, async ({ app }) => {
            test.setTimeout(300_000);
            setPlan(plan);
            setLocale(locale);
            const { problems, at } = watch(app);

            try {
                await app.setViewportSize({ width, height: 844 });
                await app.goto('/plans');
                await expect(app.locator('s-page').first()).toBeVisible();
                const variant = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts?status=reorder_now')).data[0]?.variant_id
                    ?? (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id;
                await scan(app, routes(variant), width, problems, at);
            } finally {
                setLocale(null);
                await app.setViewportSize({ width: 1280, height: 720 });
            }
            expect([...new Set(problems)], 'problems found by the sweep').toEqual([]);
        });
    }
}

/**
 * Stores in states the dev store is not in: nothing synced yet, no products at all, syncing broken.
 * A shop of their own is made for these tests and removed afterwards.
 */
test.describe('other store states', () => {
    const DOMAIN = 'e2e-states.myshopify.com';
    const remove = () => php(`$s = App\\Models\\Shop::firstWhere("domain", "${DOMAIN}"); if ($s) { app(App\\Repositories\\Contracts\\ShopRepositoryInterface::class)->purge($s); } echo json_encode(true);`);
    const state = (attributes: string) => php(`App\\Models\\Shop::firstWhere("domain", "${DOMAIN}")->forceFill(${attributes})->save(); Illuminate\\Support\\Facades\\Cache::flush(); echo json_encode(true);`);
    test.use({ shopDomain: DOMAIN });

    test.beforeAll(() => {
        remove();
        php(`App\\Models\\Shop::factory()->create(["domain" => "${DOMAIN}", "name" => "States", "plan" => "growth", "currency" => "USD", "timezone" => "UTC", "onboarded_at" => now(), "installed_at" => now()->subDays(30), "access_token_expires_at" => now()->addYear()]); echo json_encode(true);`);
    });
    test.afterAll(() => remove());

    const STATES: [string, string][] = [
        ['synced, but the store has no products', '["sync_status" => "completed", "sync_error" => null, "last_synced_at" => now(), "forecasted_at" => now()]'],
        ['first sync still running', '["sync_status" => "running", "sync_error" => null, "last_synced_at" => null, "forecasted_at" => null]'],
        ['sync failed: the app must be reopened', '["sync_status" => "failed", "sync_error" => ["code" => "reauthorize", "params" => []], "last_synced_at" => now()->subDays(3), "forecasted_at" => now()->subDays(3)]'],
        ['sync failed for an unknown reason, never synced', '["sync_status" => "failed", "sync_error" => ["code" => "unknown", "params" => []], "last_synced_at" => null, "forecasted_at" => null]'],
    ];
    for (const [name, attributes] of STATES) {
        for (const width of [1280, 390]) {
            test(`${name} · ${width}px`, async ({ app }) => {
                test.setTimeout(180_000);
                state(attributes);
                const { problems, at } = watch(app);
                await app.setViewportSize({ width, height: 844 });
                await scan(app, routes(999_999).filter((p) => !p.includes('999999')), width, problems, at);
                // For a look by eye: the home screen of this state.
                await app.goto('/');
                await settled(app);
                await app.screenshot({ path: `e2e-results/state-${STATES.findIndex((x) => x[0] === name)}-${width}.png`, fullPage: true });
                // The home screen names the real reason there is nothing to show, and the sync card is never empty.
                const heading = attributes.includes('"running"') ? 'Preparing your forecast' : attributes.includes('"failed"') ? "We couldn't import your store data" : 'No forecasts yet';
                await expect(app.locator(`s-empty-state[heading="${heading}"]`)).toBeVisible();
                expect((await app.locator('s-section[heading="Store data"]').innerText()).trim().length).toBeGreaterThan('Store data'.length);
                // A product that does not exist says so, without an error in the console.
                at('/products/999999');
                await app.goto('/products/999999');
                await expect(app.locator('s-page').first()).toBeVisible();
                await settled(app);
                expect([...new Set(problems)].filter((p) => p !== '/products/999999: 404 GET /api/forecasts/999999'), 'problems found by the sweep').toEqual([]);
            });
        }
    }
});

test.afterAll(() => {
    setLocale(null);
    setPlan('free');
});
