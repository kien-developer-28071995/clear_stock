import { useEffect } from 'react';
import { currentLocale } from '@/i18n';
import { useShop } from '@/features/shop/hooks/useShop';
import { startSupportChat } from '@/lib/supportChat';

/** Loads the support chat widget once the shop is known (no-op when the build has no Crisp id). */
export function SupportChat() {
    const { data: shop } = useShop();
    const domain = shop?.domain;
    const plan = shop?.plan;

    useEffect(() => {
        if (domain && plan) startSupportChat({ shop: domain, plan, locale: currentLocale() });
    }, [domain, plan]);

    return null;
}
