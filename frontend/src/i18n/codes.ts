import i18n from 'i18next';
import { currentLocale } from '@/i18n';
import type { Coded } from '@/types/coded';
import { formatDate, formatNumber } from '@/utils/format';

// Keys built from API codes can't be checked at compile time; unknown codes fall back.
const translate = (key: string, options: Record<string, unknown>) =>
    (i18n as unknown as { t: (key: string, options: Record<string, unknown>) => string }).t(key, options);

const isCoded = (v: unknown): v is Coded => typeof v === 'object' && v !== null && 'code' in v;
const isDate = (v: unknown): v is string => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v);

/**
 * Params are raw values: numbers are formatted for the current language, Y-m-d dates
 * shortened, nested codes translated in the same namespace, and lists joined
 * ("a, b and c"). `count` stays a number so i18next picks the plural form.
 */
function formatParams(namespace: string, params: Record<string, unknown>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(params)) {
        if (key === 'count') out.count = value;
        else if (isCoded(value)) out[key] = translateCode(namespace, value);
        else if (Array.isArray(value)) {
            const items = value.map((v) => (isCoded(v) ? translateCode(namespace, v) : String(v)));
            out[key] = new Intl.ListFormat(currentLocale(), { type: 'conjunction' }).format(items);
        } else if (typeof value === 'number') out[key] = formatNumber(value, 2);
        else if (isDate(value)) out[key] = formatDate(value);
        else out[key] = value;
    }
    return out;
}

/** Translate `namespace.code`, or `namespace.fallback` when the code is unknown. */
export function translateCode(namespace: string, coded: Coded, fallback = 'unknown'): string {
    const params = formatParams(namespace, coded.params ?? {});
    // Pass the params so plural-only keys (code_one / code_other) are found.
    const key = i18n.exists(`${namespace}.${coded.code}`, params) ? `${namespace}.${coded.code}` : `${namespace}.${fallback}`;
    return translate(key, params);
}
