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
    return i18n.resolvedLanguage ?? DEFAULT_LOCALE;
}

/** Initialise with the Shopify admin language (App Bridge `shopify.config.locale`). */
export async function initI18n(adminLocale: string | undefined): Promise<void> {
    const lng = resolveLocale(adminLocale);
    const resources: Record<string, { translation: typeof en }> = { en: { translation: en } };

    // Load the admin language before init so the first render is already translated.
    const loader = loaders[`./locales/${lng}.json`];
    if (lng !== DEFAULT_LOCALE && loader) resources[lng] = { translation: (await loader()).default };

    await i18n.use(initReactI18next).init({
        lng,
        fallbackLng: DEFAULT_LOCALE,
        supportedLngs: SUPPORTED_LOCALES,
        resources,
        interpolation: { escapeValue: false }, // React escapes
        returnNull: false,
    });

    document.documentElement.lang = lng;
}

export default i18n;
