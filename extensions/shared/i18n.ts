/**
 * Translating API codes in an extension, the same way the app does (app/frontend/src/i18n/codes.ts).
 * The `status`, `confidence` and `explanation` sections of the locale files are copied
 * from the app by `npm run locales`, so both say exactly the same thing.
 */
export interface Coded {
    code: string;
    params?: Record<string, unknown>;
}

/** The parts of the extension i18n API used here. */
export interface I18nLike {
    translate: (key: string, options?: Record<string, string | number>) => string;
    formatNumber: (value: number, options?: Intl.NumberFormatOptions) => string;
    formatDate: (date: Date, options?: Intl.DateTimeFormatOptions) => string;
}

const isCoded = (v: unknown): v is Coded => typeof v === 'object' && v !== null && 'code' in v;
const isDate = (v: unknown): v is string => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v);

export function formatNumber(i18n: I18nLike, value: number, maxFractionDigits = 1): string {
    return i18n.formatNumber(value, { maximumFractionDigits: maxFractionDigits });
}

export function formatDate(i18n: I18nLike, ymd: string | null): string {
    if (!ymd) return '—';
    const [y, m, d] = ymd.split('-').map(Number);
    return i18n.formatDate(new Date(Date.UTC(y, m - 1, d)), { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

/** Translate `namespace.code`, falling back to `namespace.unknown` for codes this version doesn't know. */
export function translateCode(i18n: I18nLike, namespace: string, coded: Coded): string {
    const params: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(coded.params ?? {})) {
        if (key === 'count' && typeof value === 'number') params.count = value;
        else if (isCoded(value)) params[key] = translateCode(i18n, namespace, value);
        else if (Array.isArray(value)) {
            const items = value.map((v) => (isCoded(v) ? translateCode(i18n, namespace, v) : String(v)));
            params[key] = new Intl.ListFormat(i18n.translate('locale'), { type: 'conjunction' }).format(items);
        } else if (typeof value === 'number') params[key] = formatNumber(i18n, value, 2);
        else if (isDate(value)) params[key] = formatDate(i18n, value);
        else params[key] = String(value ?? '');
    }
    const text = i18n.translate(`${namespace}.${coded.code}`, params);
    // A missing key comes back as the key itself.
    return text === `${namespace}.${coded.code}` ? i18n.translate(`${namespace}.unknown`) : text;
}
