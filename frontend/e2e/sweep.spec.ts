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
    // A translation key shown instead of its text: lower.camel.dotted, at least two dots or a known namespace.
    /(^|\s)(common|nav|errors|status|table|home|actions|product|products|settings|suppliers|plans|insights|orders|costs|budget|snooze|projection|history|feedback|tabs|sections|explanation|apiErrors|validation)\.[A-Za-z_]+(\.[A-Za-z_]+)*(\s|$)/,
];

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
            const problems: string[] = [];
            let where = '';
            app.on('console', (m) => {
                // The browser's own note about a failed request: the response check below names it.
                if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) problems.push(`${where}: console error: ${m.text().slice(0, 200)}`);
            });
            app.on('response', (r) => {
                const path = new URL(r.url()).pathname;
                // 402 = the plan lacks it (the page shows an upgrade prompt and must not ask at all: reported).
                if (path.startsWith('/api/') && r.status() >= 400) problems.push(`${where}: ${r.status()} ${r.request().method()} ${path}`);
            });

            try {
                await app.setViewportSize({ width, height: 844 });
                await app.goto('/plans');
                await expect(app.locator('s-page').first()).toBeVisible();
                const variant = (await api<{ data: { variant_id: number }[] }>(app, '/forecasts?status=reorder_now')).data[0]?.variant_id
                    ?? (await api<{ data: { variant_id: number }[] }>(app, '/forecasts')).data[0].variant_id;

                for (const path of routes(variant)) {
                    where = path;
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
                        const bad = BROKEN.find((re) => re.test(line));
                        if (bad) problems.push(`${path}: shows "${line.slice(0, 120)}"`);
                    }
                    const overflow = await app.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                    if (overflow > 3) problems.push(`${path}: ${overflow}px wider than a ${width}px screen`);
                }
            } finally {
                setLocale(null);
                await app.setViewportSize({ width: 1280, height: 720 });
            }
            expect([...new Set(problems)], 'problems found by the sweep').toEqual([]);
        });
    }
}

test.afterAll(() => {
    setLocale(null);
    setPlan('free');
});
