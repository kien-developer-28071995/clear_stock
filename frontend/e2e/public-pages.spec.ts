import { expect, test } from '@playwright/test';

/** Privacy and support are public pages: opened directly, outside the Shopify admin (no App Bridge). */
test('privacy policy and support render without the Shopify admin, in English and Vietnamese', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    // The real shell from Laravel, with Vite's dev-server origin (the tunnel) made relative so
    // assets load from the E2E origin (in production they are same-origin build files).
    await page.route(
        (url) => /^\/(privacy|support)/.test(url.pathname),
        async (route) => {
            const response = await route.fetch();
            const html = (await response.text()).replace(/https:\/\/[^/"']+(\/(?:@vite\/|@react-refresh|src\/))/g, '$1');
            await route.fulfill({ response, body: html });
        },
    );

    await page.goto('/privacy?lang=en');
    await expect(page.getByRole('heading', { level: 1, name: 'Privacy policy' })).toBeVisible();
    await expect(page.getByText('The app stores no personal data of your customers.')).toBeVisible();
    await expect(page).toHaveTitle(/Privacy policy/);
    await page.screenshot({ path: 'e2e-results/public-privacy.png', fullPage: true });

    // The footer links switch pages in place.
    await page.locator('footer').getByRole('link', { name: 'Support' }).click();
    await expect(page).toHaveURL(/\/support/);
    await expect(page.getByRole('heading', { level: 1, name: 'Support' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'How is the forecast calculated?' })).toBeVisible();

    await page.goto('/support?lang=vi');
    await expect(page.getByRole('heading', { level: 1, name: 'Hỗ trợ' })).toBeVisible();
    await page.screenshot({ path: 'e2e-results/public-support-vi.png', fullPage: true });

    expect(errors).toEqual([]);
});
