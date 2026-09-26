// Verifies every locale file has exactly the keys of en.json, with the plural forms
// its language needs (Intl.PluralRules), so no screen ever shows a raw key.
// Usage: node scripts/check-i18n.mjs
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const dir = join(import.meta.dirname, '../src/i18n/locales');
const PLURAL = /_(zero|one|two|few|many|other)$/;

const flatten = (obj, prefix = '') =>
    Object.entries(obj).flatMap(([k, v]) => (v && typeof v === 'object' ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`]));

const load = (file) => flatten(JSON.parse(readFileSync(join(dir, file), 'utf8')));

/** Base keys + which of them are plural. */
const shape = (keys) => {
    const base = new Set();
    const plural = new Set();
    for (const key of keys) {
        const b = key.replace(PLURAL, '');
        base.add(b);
        if (PLURAL.test(key)) plural.add(b);
    }
    return { base, plural };
};

const en = shape(load('en.json'));
let errors = 0;

for (const file of readdirSync(dir).filter((f) => f.endsWith('.json') && f !== 'en.json')) {
    const locale = file.replace('.json', '');
    const keys = load(file);
    const { base } = shape(keys);
    const needed = new Intl.PluralRules(locale).resolvedOptions().pluralCategories;

    for (const k of en.base) {
        if (!base.has(k)) { console.error(`${locale}: missing "${k}"`); errors++; }
        if (en.plural.has(k)) {
            for (const cat of needed) {
                if (!keys.includes(`${k}_${cat}`)) { console.error(`${locale}: missing plural "${k}_${cat}"`); errors++; }
            }
        }
    }
    for (const k of base) if (!en.base.has(k)) { console.error(`${locale}: extra key "${k}" (not in en.json)`); errors++; }
}

if (errors) { console.error(`\n${errors} translation problem(s).`); process.exit(1); }
console.log('Translations OK:', readdirSync(dir).filter((f) => f.endsWith('.json')).join(', '));
