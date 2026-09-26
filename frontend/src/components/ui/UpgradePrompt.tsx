import { useTranslation } from 'react-i18next';

/** Friendly "this is in plan X" callout that links to the pricing page. */
export function UpgradePrompt({ plan, children }: { plan: 'starter' | 'growth'; children: string }) {
    const { t } = useTranslation();

    return (
        <s-banner tone="info" heading={t('upgrade.includedIn', { plan: t(`plans.names.${plan}`) })}>
            <s-paragraph>{children}</s-paragraph>
            <s-button slot="secondary-actions" href="/plans">
                {t('upgrade.seePlans')}
            </s-button>
        </s-banner>
    );
}
