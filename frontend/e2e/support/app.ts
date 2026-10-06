import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { test as base, expect, type Page } from '@playwright/test';

export type PlanKey = 'free' | 'starter' | 'growth';

const ROOT = path.resolve(import.meta.dirname, '../../..');

/** Runs an artisan command in the app container (dev commands only work with APP_ENV=local). */
export function artisan(...args: string[]): string {
    return execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', 'artisan', ...args], { cwd: ROOT, encoding: 'utf8' }).trim();
}

/** Last line of the output: commands may log above it. */
function lastLine(output: string): string {
    return output.split('\n').filter(Boolean).at(-1) ?? '';
}

/** A session token for the dev shop, as App Bridge would hand out. */
export function sessionToken(shop: string | null = null): string {
    return lastLine(artisan('dev:session-token', '--ttl=3600', ...(shop ? [`--shop=${shop}`] : [])));
}

export function setPlan(plan: PlanKey): void {
    artisan('dev:set-plan', plan);
}

/** Current state of the shop, read straight from the API (not the UI). */
export async function api<T>(page: Page, url: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
    return page.evaluate(
        async ({ url, init }) => {
            const token = await window.shopify.idToken();
            const res = await fetch(`/api${url}`, {
                method: init.method ?? 'GET',
                headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
                body: init.body === undefined ? undefined : JSON.stringify(init.body),
            });
            const text = await res.text();
            return (text ? JSON.parse(text) : null) as T;
        },
        { url, init },
    );
}

/** The app's page (frontend/index.html) without App Bridge's CDN script. */
const SHELL = `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clear Stock</title>
<style>ui-save-bar { display: none; } body { background: #f1f1f1; }</style>
<script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
<script>window.__APP_CONFIG__ = { appName: 'Clear Stock' };</script>
<script type="module">
  import RefreshRuntime from '/@react-refresh';
  RefreshRuntime.injectIntoGlobalHook(window);
  window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => (t) => t;
  window.__vite_plugin_react_preamble_installed__ = true;
</script>
<script type="module" src="/@vite/client"></script>
</head><body><div id="root"></div><script type="module" src="/src/main.tsx"></script></body></html>`;

declare global {
    interface Window {
        __e2e: { toasts: string[]; saveBar: Record<string, boolean>; pickerSelection: unknown[]; scopes: string[]; grantScopes: boolean; scopeRequests: string[][]; reviewRequests: number; reviewCode: string };
    }
}

/** Stub of the App Bridge APIs the app uses (`shopify.*`). */
export function stubAppBridge({ token, locale }: { token: string; locale: string }) {
    // reviewCode: what Shopify's review dialog answers. "cooldown-period" (not shown this time) leaves the shop as it was.
    window.__e2e = { toasts: [], saveBar: {}, pickerSelection: [], scopes: [], grantScopes: true, scopeRequests: [], reviewRequests: 0, reviewCode: 'cooldown-period' };
    (window as unknown as { shopify: unknown }).shopify = {
        config: { locale },
        idToken: async () => token,
        toast: { show: (message: string) => void window.__e2e.toasts.push(message) },
        saveBar: {
            show: async (id: string) => void (window.__e2e.saveBar[id] = true),
            hide: async (id: string) => void (window.__e2e.saveBar[id] = false),
            leaveConfirmation: async () => undefined,
        },
        resourcePicker: async () => window.__e2e.pickerSelection,
        reviews: {
            request: async () => {
                window.__e2e.reviewRequests++;
                const code = window.__e2e.reviewCode;
                return { success: code === 'success', code, message: '' };
            },
        },
        // Optional scopes: the merchant approves (grantScopes) or declines the dialog.
        scopes: {
            query: async () => ({ granted: window.__e2e.scopes, required: [], optional: [] }),
            request: async (scopes: string[]) => {
                window.__e2e.scopeRequests.push(scopes);
                if (window.__e2e.grantScopes) window.__e2e.scopes.push(...scopes);
                return { result: window.__e2e.grantScopes ? 'granted-all' : 'declined-all', detail: { granted: window.__e2e.scopes, required: [], optional: [] } };
            },
        },
    };
}

export const test = base.extend<{ app: Page; shopDomain: string | null }>({
    // Another shop than the dev store (`test.use({ shopDomain })`): a store in a state the dev store is not in.
    shopDomain: [null, { option: true }],
    app: async ({ page, baseURL, shopDomain }, use) => {
        const token = sessionToken(shopDomain);
        await page.addInitScript(stubAppBridge, { token, locale: 'en' });
        // Every page navigation gets the shell; API, Vite and CDN requests go through.
        await page.route(
            (url) => url.origin === new URL(baseURL!).origin && !/^\/(api|src|node_modules|@|__vite)/.test(url.pathname) && !/\.\w+$/.test(url.pathname),
            (route) => (route.request().resourceType() === 'document' ? route.fulfill({ contentType: 'text/html', body: SHELL }) : route.continue()),
        );
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        // A report to /api/client-errors means the app crashed (ErrorBoundary).
        page.on('request', (r) => r.url().includes('/api/client-errors') && errors.push(`client-error: ${r.postData()}`));
        await use(page);
        expect(errors, 'no uncaught errors in the page').toEqual([]);
    },
});

export { expect };

/** Opens a route and waits until the page heading is rendered and loading is done. */
export async function open(page: Page, route: string): Promise<void> {
    await page.goto(route);
    await expect(page.locator('s-page').first()).toBeVisible();
    await settled(page);
}

/**
 * Waits until no spinner or loading table is left. Light DOM only (Polaris has its own
 * inside shadow roots), and not inside modals, which render their content while closed.
 */
export async function settled(page: Page): Promise<void> {
    await page.waitForFunction(
        () => [...document.querySelectorAll('#root s-spinner, #root [loading]')].every((e) => e.closest('s-modal')),
        null,
        { timeout: 15_000 },
    );
}

export function toasts(page: Page): Promise<string[]> {
    return page.evaluate(() => window.__e2e.toasts);
}

/** Clicks Save / Discard in a contextual save bar (App Bridge shows it in the admin chrome). */
export async function saveBar(page: Page, id: string, action: 'save' | 'discard' = 'save'): Promise<void> {
    await expect.poll(() => page.evaluate((id) => window.__e2e.saveBar[id], id), { message: `save bar ${id} shown` }).toBe(true);
    await page.locator(`ui-save-bar#${id} button`).nth(action === 'save' ? 0 : 1).evaluate((b: HTMLButtonElement) => b.click());
    await expect.poll(() => page.evaluate((id) => window.__e2e.saveBar[id], id), { message: `save bar ${id} hidden` }).toBe(false);
}

/** What the resource picker returns next (Shopify's picker can't run outside the admin). */
export async function pickNext(page: Page, variants: { id: string; displayName: string }[]): Promise<void> {
    await page.evaluate((v) => (window.__e2e.pickerSelection = v), variants);
}

/** Runs PHP in the app container and returns its JSON output (last line). */
export function php<T>(code: string): T {
    return JSON.parse(lastLine(artisan('tinker', `--execute=${code}`))) as T;
}

/** Variants of the dev shop by title: id, GID and name. */
export function variants(): { id: number; gid: string; name: string }[] {
    return php(
        'echo json_encode(App\\Models\\Variant::where("shop_id", App\\Models\\Shop::first()->id)->get()->map(fn ($v) => ["id" => $v->id, "gid" => "gid://shopify/ProductVariant/".$v->shopify_variant_id, "name" => $v->displayName()]));',
    );
}
