import { useTranslation } from 'react-i18next';
import { useDismissed } from '@/hooks/useDismissed';

/**
 * Friendly "this is in plan X" callout that links to the pricing page. Promotional,
 * so merchants can dismiss it (Built for Shopify 4.3.6); `id` remembers which one.
 */
export function UpgradePrompt({ id, plan, children }: { id: string; plan: 'starter' | 'growth'; children: string }) {
    const { t } = useTranslation();
    const [dismissed, dismiss] = useDismissed(`upgrade-${id}`);
    if (dismissed) return null;

    return (
        <s-banner tone="info" heading={t('upgrade.includedIn', { plan: t(`plans.names.${plan}`) })} dismissible onDismiss={dismiss}>
            <s-paragraph>{children}</s-paragraph>
            <s-button slot="secondary-actions" href="/plans">
                {t('upgrade.seePlans')}
            </s-button>
        </s-banner>
    );
}
