import de from './locales/de.json';
import en from './locales/en.json';
import es from './locales/es.json';
import fr from './locales/fr.json';
import pt from './locales/pt.json';
import vi from './locales/vi.json';

/**
 * Same languages as the app (backend supported_locales). English lives at the root (/privacy),
 * the others under their code (/vi/privacy). Add a language: a locale file + an entry below.
 */
export const LOCALES = ['en', 'vi', 'es', 'de', 'fr', 'pt'] as const;
export type Locale = (typeof LOCALES)[number];
export const DEFAULT_LOCALE: Locale = 'en';

/** Each language in its own name, for the language menu. */
export const LANGUAGE_NAMES: Record<Locale, string> = {
    en: 'English',
    vi: 'Tiếng Việt',
    es: 'Español',
    de: 'Deutsch',
    fr: 'Français',
    pt: 'Português',
};

type Messages = typeof en;
const MESSAGES: Record<Locale, Messages> = { en, vi, es, de, fr, pt };

type Params = Record<string, string | number>;

function lookup(messages: unknown, key: string): unknown {
    return key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], messages);
}

const interpolate = (text: string, params: Params = {}) =>
    text.replace(/\{\{(\w+)\}\}/g, (match, name: string) => (name in params ? String(params[name]) : match));

/** Translator for one page: t('hero.title'), t('demo.units', { count: 5 }), list('pricing.plans.free.items'). */
export function translator(locale: Locale) {
    const get = (key: string): unknown => lookup(MESSAGES[locale], key) ?? lookup(MESSAGES[DEFAULT_LOCALE], key);

    const t = (key: string, params?: Params): string => {
        const value = get(key);
        if (typeof value !== 'string') throw new Error(`Missing translation "${key}" (${locale})`);
        return interpolate(value, params);
    };

    const list = (key: string, params?: Params): string[] => {
        const value = get(key);
        if (!Array.isArray(value)) throw new Error(`Missing translation list "${key}" (${locale})`);
        return value.map((item: string) => interpolate(item, params));
    };

    return { t, list };
}

export const isLocale = (value: string | undefined): value is Locale => LOCALES.includes(value as Locale);

/** The [...lang] route param of a page: undefined for English (site root). */
export const localeFromParam = (param: string | undefined): Locale => (isLocale(param) ? param : DEFAULT_LOCALE);

/** getStaticPaths for a page that exists in every language. */
export const localeStaticPaths = () => LOCALES.map((locale) => ({ params: { lang: locale === DEFAULT_LOCALE ? undefined : locale } }));

/** URL of a page in a language: localePath('vi', 'privacy') = /vi/privacy, localePath('en', '') = /. */
export function localePath(locale: Locale, page: string): string {
    const prefix = locale === DEFAULT_LOCALE ? '' : `/${locale}`;
    return page ? `${prefix}/${page}` : prefix || '/';
}

export function formatters(locale: Locale) {
    return {
        number: (value: number, digits = 0) => new Intl.NumberFormat(locale, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value),
        money: (value: number, currency: string) =>
            new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: Number.isInteger(value) ? 0 : 2 }).format(value),
        /** A day and month, e.g. "Oct 9" / "9 thg 10". */
        dayMonth: (date: Date) => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(date),
        /** A full date for policies, e.g. "September 26, 2026". */
        longDate: (ymd: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${ymd}T00:00:00Z`)),
    };
}
