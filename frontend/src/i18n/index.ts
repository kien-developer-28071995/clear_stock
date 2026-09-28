import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from '@/i18n/locales/en.json';

/**
 * Every file in ./locales is a supported language: adding `fr.json` is all it takes
 * to ship French (the key check in `npm run i18n:check` keeps files in sync).
 * English is bundled as the fallback; other languages load on demand.
 */
const loaders = import.meta.glob<{ default: typeof en }>('./locales/*.json');

export const DEFAULT_LOCALE = 'en';
export const SUPPORTED_LOCALES = Object.keys(loaders).map((path) => path.replace(/^.*\/(.+)\.json$/, '$1'));

/** "vi-VN" -> "vi", "pt-BR" -> "pt-BR" if supported, else "pt" if supported, else English. */
export function resolveLocale(tag: string | null | undefined): string {
    if (!tag) return DEFAULT_LOCALE;
    const exact = SUPPORTED_LOCALES.find((l) => l.toLowerCase() === tag.toLowerCase());
    if (exact) return exact;
    const base = tag.split('-')[0].toLowerCase();
    return SUPPORTED_LOCALES.find((l) => l.toLowerCase() === base) ?? DEFAULT_LOCALE;
}

/** The locale currently used for text and Intl formatting. */
export function currentLocale(): string {
    // Not resolvedLanguage: it is computed before a lazily loaded language's strings exist.
    return resolveLocale(i18n.language);
}

// The language chosen in Settings is saved on the shop; it is also remembered in this
// browser so the next visit starts in the right language before the API answers.
const PREFERENCE_KEY = 'clear_stock.locale';
let adminLocale: string | undefined;

function storedPreference(): string | null {
    try {
        return localStorage.getItem(PREFERENCE_KEY);
    } catch {
        return null;
    }
}

function storePreference(locale: string | null): void {
    try {
        if (locale) localStorage.setItem(PREFERENCE_KEY, locale);
        else localStorage.removeItem(PREFERENCE_KEY);
    } catch {
        // storage blocked: the shop setting still applies once loaded
    }
}

async function loadBundle(lng: string): Promise<void> {
    const loader = loaders[`./locales/${lng}.json`];
    if (lng !== DEFAULT_LOCALE && loader && !i18n.hasResourceBundle(lng, 'translation')) {
        i18n.addResourceBundle(lng, 'translation', (await loader()).default, true, true);
    }
}

/** The Shopify admin language, resolved to a supported locale ("Follow Shopify admin"). */
export function adminLanguage(): string {
    return resolveLocale(adminLocale);
}

/** Native name of a language: "English", "Tiếng Việt". */
export function languageName(locale: string): string {
    return new Intl.DisplayNames([locale], { type: 'language' }).of(locale) ?? locale;
}

/**
 * Switch to the language chosen in Settings (null = follow the Shopify admin language).
 * Other languages than English are downloaded on first use.
 */
export async function applyLanguagePreference(preference: string | null): Promise<void> {
    storePreference(preference && SUPPORTED_LOCALES.includes(preference) ? preference : null);
    const lng = resolveLocale(preference ?? adminLocale);
    if (lng === i18n.language) return;
    await loadBundle(lng);
    await i18n.changeLanguage(lng);
    document.documentElement.lang = lng;
}

/** Initialise with the saved preference, else the Shopify admin language (`shopify.config.locale`). */
export async function initI18n(shopifyLocale: string | undefined): Promise<void> {
    adminLocale = shopifyLocale;
    const lng = resolveLocale(storedPreference() ?? shopifyLocale);

    await i18n.use(initReactI18next).init({
        lng,
        fallbackLng: DEFAULT_LOCALE,
        supportedLngs: SUPPORTED_LOCALES,
        resources: { en: { translation: en } },
        interpolation: { escapeValue: false }, // React escapes
        returnNull: false,
    });
    // Load the language before the first render so nothing flashes in English.
    await loadBundle(lng);

    document.documentElement.lang = lng;
}

export default i18n;
