import { useEffect } from 'react';
import { applyLanguagePreference } from '@/i18n';
import { useShop } from '@/features/shop/hooks/useShop';

/** Applies the language saved on the shop (Settings → Language) once the shop loads. */
export function LanguageSync() {
    const preference = useShop().data?.locale;

    useEffect(() => {
        if (preference !== undefined) void applyLanguagePreference(preference);
    }, [preference]);

    return null;
}
