/**
 * Every locale file has exactly the keys of en.json (lists: the same length), and keeps
 * each {{placeholder}} of the English text. Part of `npm run build`.
 */
import fs from 'node:fs';
import path from 'node:path';

const DIR = path.resolve(import.meta.dirname, '../src/i18n/locales');
const read = (file) => JSON.parse(fs.readFileSync(path.join(DIR, file), 'utf8'));

function flatten(node, prefix = '', out = {}) {
    if (typeof node === 'string') out[prefix] = node;
    else for (const [key, value] of Object.entries(node)) flatten(value, prefix ? `${prefix}.${key}` : key, out);
    return out;
}

const placeholders = (text) => [...text.matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]).sort().join(',');
const en = flatten(read('en.json'));
let problems = 0;
const report = (message) => {
    problems++;
    console.error(message);
};

for (const file of fs.readdirSync(DIR).filter((f) => f.endsWith('.json') && f !== 'en.json')) {
    const other = flatten(read(file));
    for (const key of Object.keys(en)) {
        if (!(key in other)) report(`${file}: missing ${key}`);
        else if (placeholders(en[key]) !== placeholders(other[key])) report(`${file}: ${key} placeholders differ from en.json`);
    }
    for (const key of Object.keys(other)) if (!(key in en)) report(`${file}: extra key ${key}`);
}

if (problems) {
    console.error(`\n${problems} translation problem(s).`);
    process.exit(1);
}
console.log('Translations OK');
