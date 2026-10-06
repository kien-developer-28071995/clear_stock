import path from 'node:path';
import { expect, open, settled, test } from './support/app';
import type { Page } from '@playwright/test';

/**
 * Draft walkthrough video (WebM, 1280x720, English captions) of the v1 app, as a script for the
 * App Store screencast. It does NOT show installing the app in the Shopify admin: record that part
 * yourself (docs/APP_STORE.md). Run with `make listing-video`; written to docs/listing/walkthrough.webm.
 * Same cosmetics as the screenshots (setup guide hidden, admin nav hidden, USD).
 */
test.skip(!process.env.LISTING_VIDEO, 'run with make listing-video');
test.use({ viewport: { width: 1280, height: 720 }, video: { mode: 'on', size: { width: 1280, height: 720 } } });
test.setTimeout(180_000);

const OUT = path.resolve('../../docs/listing/walkthrough.webm');
const SAMPLE_CSV = path.resolve('../../docs/sample-purchase-orders.csv');

/** Caption at the bottom of the screen; kept across page loads (sessionStorage). */
async function caption(page: Page, text: string, hold = 3500): Promise<void> {
    await page.evaluate((t) => {
        sessionStorage.setItem('caption', t);
        const el = document.getElementById('walkthrough-caption');
        if (el) el.textContent = t;
    }, text);
    await page.waitForTimeout(hold);
}

async function scroll(page: Page, y: number): Promise<void> {
    await page.evaluate((dy) => window.scrollBy({ top: dy, behavior: 'smooth' }), y);
    await page.waitForTimeout(1200);
}

test('walkthrough', async ({ app }) => {
    await app.route('**/api/setup-guide', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.data = { ...body.data, dismissed: true, tips_dismissed: ['home_actions', 'home_runway', 'product_explanation'] };
        await route.fulfill({ response, json: body });
    });
    await app.route(/\/api\/(shop|dashboard|billing|what-if|forecasts)/, async (route) => {
        const response = await route.fetch();
        await route.fulfill({ response, body: (await response.text()).replace(/"currency":"[A-Z]{3}"/g, '"currency":"USD"') });
    });
    await app.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const style = document.createElement('style');
            style.textContent = `s-app-nav { display: none !important; }
                #walkthrough-caption { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); z-index: 99999;
                  max-width: 1000px; padding: 12px 20px; border-radius: 10px; background: rgba(20, 20, 20, .86); color: #fff;
                  font: 500 18px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: center; }`;
            document.head.appendChild(style);
            const el = document.createElement('div');
            el.id = 'walkthrough-caption';
            el.textContent = sessionStorage.getItem('caption') ?? '';
            document.body.appendChild(el);
        });
    });

    await open(app, '/');
    await caption(app, 'Clear Stock forecasts when every product runs out, and how much to reorder.', 4500);
    await caption(app, 'Home: what to order today, and money tied up in slow stock.');

    await open(app, '/products');
    await caption(app, 'Every product with its stock, sales per day, days left and suggested order.');
    await app.getByRole('combobox', { name: 'ABC class' }).selectOption('A');
    await settled(app);
    await caption(app, 'Filter by status, vendor or ABC class: class A makes 80% of your revenue.');

    await open(app, '/products?status=reorder_now');
    await app.locator('s-table-body s-table-row', { has: app.locator('s-badge', { hasText: 'Reorder now' }) }).first().locator('s-link').first().click();
    await settled(app);
    await caption(app, 'Open a product: the forecast, and when to reorder.');
    await scroll(app, 380);
    await caption(app, '"Why these numbers?" explains every figure: sales windows, out-of-stock days left out, lead time, safety stock.', 5500);
    await scroll(app, 420);
    await caption(app, 'Know something the data doesn\'t? Adjust the sales rate, lead time, minimum order or pack size.', 4500);

    await open(app, '/reorder');
    await caption(app, 'Reorder: everything to order in the next 7 days, grouped by urgency.');
    await app.getByRole('button', { name: 'Export purchase order' }).first().click();
    await caption(app, 'Export a purchase order as a spreadsheet, or in Shopify\'s purchase order import format.');
    await app.keyboard.press('Escape');

    await open(app, '/insights');
    await caption(app, 'Insights: days of stock left per product, ABC classes, overstock and slow movers.');
    await scroll(app, 500);
    await app.waitForTimeout(1500);

    await open(app, '/what-if?growth=0');
    await caption(app, 'What if sales grow? Try a scenario before you commit.', 3000);
    await app.locator('s-button', { hasText: '+50%' }).click();
    await settled(app);
    await expect(app.getByText(/Sales \+50%/)).toBeVisible();
    await caption(app, '+50%: which products to order sooner, and how many more units.', 4500);

    await open(app, '/suppliers/import');
    await caption(app, 'Moving from Stocky? Import your purchase order CSV.', 3000);
    const input = app.locator('s-drop-zone input[type="file"]');
    if (await input.count()) {
        await input.setInputFiles(SAMPLE_CSV);
        await settled(app);
        await app.waitForTimeout(800);
        await scroll(app, 900);
        await caption(app, 'Suppliers and their lead times are worked out from your purchase orders.', 4500);
    }

    await open(app, '/plans');
    await caption(app, 'Flat price, no share of your sales, no contract. Your price never goes up.', 4500);

    const video = app.video();
    await app.close();
    await video?.saveAs(OUT);
});
