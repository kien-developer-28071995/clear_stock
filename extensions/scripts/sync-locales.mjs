/**
 * Copies the app's translations of API codes (status, confidence, explanation) into each
 * extension's locale files, so the product page block explains forecasts in the same
 * words as the app. i18next plural keys (`key_one`, `key_other`) become Shopify's
 * nested plural objects (`key: { one, other }`). Extension-specific keys are kept.
 *
 *   npm run locales          update the files
 *   npm run locales:check    fail if they are out of date
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const APP_LOCALES = path.resolve(ROOT, '../app/frontend/src/i18n/locales');
const SHARED = ['status', 'confidence', 'explanation'];
const EXTENSIONS = ['product-forecast-block'];
const PLURAL = /^(.*)_(zero|one|two|few|many|other)$/;
const check = process.argv.includes('--check');

function toShopifyPlurals(section) {
    const out = {};
    for (const [key, value] of Object.entries(section)) {
        const plural = PLURAL.exec(key);
        if (plural && typeof value === 'string') (out[plural[1]] ??= {})[plural[2]] = value;
        else out[key] = typeof value === 'object' && value !== null ? toShopifyPlurals(value) : value;
    }
    return out;
}

let stale = 0;
for (const file of fs.readdirSync(APP_LOCALES).filter((f) => f.endsWith('.json'))) {
    const lang = path.basename(file, '.json');
    const app = JSON.parse(fs.readFileSync(path.join(APP_LOCALES, file), 'utf8'));
    for (const ext of EXTENSIONS) {
        const target = path.join(ROOT, ext, 'locales', lang === 'en' ? 'en.default.json' : `${lang}.json`);
        const current = fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '{}';
        const data = JSON.parse(current);
        for (const section of SHARED) data[section] = toShopifyPlurals(app[section] ?? {});
        const next = JSON.stringify(data, null, 4) + '\n';
        if (next === current) continue;
        stale++;
        if (check) console.error(`Out of date: ${path.relative(ROOT, target)} (run npm run locales)`);
        else fs.writeFileSync(target, next);
    }
}
if (check && stale) process.exit(1);
console.log(check ? 'Extension locales are up to date.' : `Extension locales updated (${stale} file(s)).`);
