import 'i18next';
import type en from '@/i18n/locales/en.json';

// Type-check translation keys against the English file.
declare module 'i18next' {
    interface CustomTypeOptions {
        defaultNS: 'translation';
        resources: { translation: typeof en };
    }
}
