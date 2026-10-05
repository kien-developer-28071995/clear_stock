import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests: the real app (Vite dev server + Laravel API + dev database) in
 * Chromium, outside the Shopify admin. App Bridge is stubbed (see e2e/support/app.ts)
 * and plans are switched with `php artisan dev:set-plan`, so tests run one at a time.
 *
 * Needs `make up`, a synced dev store and APP_ENV=local. Run: `make e2e`.
 */
export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 15_000 },
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'e2e-report' }]],
    outputDir: 'e2e-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
        ...devices['Desktop Chrome'],
        // E2E_PHONE=1: the same suite on a phone-sized screen (what works on a desktop must be reachable there too).
        ...(process.env.E2E_PHONE ? { viewport: { width: 390, height: 844 } } : {}),
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
