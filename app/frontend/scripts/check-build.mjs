// Checks the built frontend (dist/) before it is published: the page must carry the values it
// was built with, and nothing of the build may still point at a placeholder.
//   node scripts/check-build.mjs            (after `npm run build`, same environment)
import { readFileSync, readdirSync } from 'node:fs';

const fail = (message) => {
    console.error(`Frontend build check failed: ${message}`);
    process.exit(1);
};
const page = readFileSync('dist/index.html', 'utf8');
const key = process.env.VITE_SHOPIFY_API_KEY;
const api = (process.env.VITE_API_URL ?? '').replace(/\/+$/, '');

if (/%VITE_[A-Z_]+%/.test(page)) fail(`index.html still has a placeholder: ${page.match(/%VITE_[A-Z_]+%/)[0]}`);
if (!key || !page.includes(`<meta name="shopify-api-key" content="${key}">`)) fail('index.html lacks the shopify-api-key the build was given (App Bridge cannot start)');
if (!page.includes('https://cdn.shopify.com/shopifycloud/app-bridge.js')) fail('index.html does not load App Bridge');
if (page.indexOf('app-bridge.js') > page.indexOf('type="module"')) fail('App Bridge must load before the app code');
if (!/^https?:\/\//.test(api)) fail('VITE_API_URL must be the backend origin (https://...)');

const scripts = readdirSync('dist/assets').filter((f) => f.endsWith('.js'));
if (!scripts.some((f) => readFileSync(`dist/assets/${f}`, 'utf8').includes(api))) fail(`no script knows ${api}: the app would call the wrong backend`);

console.log(`Frontend build OK: ${scripts.length} scripts, API ${api}`);
