import { useTranslation } from 'react-i18next';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import type { Settings } from '@/features/settings/types';

const TRIGGERS = ['productReorder', 'productStockout', 'supplierReorder'] as const;

/**
 * Shopify Flow triggers (Growth). Workflows are built in the Flow app; the app only sends
 * the triggers, and only while a workflow uses one of them.
 */
export function FlowSection({ flow }: { flow: Settings['flow'] }) {
    const { t } = useTranslation();

    return (
        <s-section heading={t('flow.heading')}>
            <s-stack gap="base">
                {!flow.available && <UpgradePrompt id="flow-triggers" plan="growth">{t('flow.locked')}</UpgradePrompt>}
                <s-paragraph>{t('flow.intro')}</s-paragraph>
                <s-unordered-list>
                    {TRIGGERS.map((key) => (
                        <s-list-item key={key}>
                            <s-text type="strong">{t(`flow.triggers.${key}.name`)}</s-text>: {t(`flow.triggers.${key}.body`)}
                        </s-list-item>
                    ))}
                </s-unordered-list>
                {flow.available && (
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                        {flow.active ? <s-badge tone="success">{t('flow.active')}</s-badge> : <s-badge>{t('flow.inactive')}</s-badge>}
                        <s-link href="shopify://admin/apps/flow" target="_top">{t('flow.open')}</s-link>
                    </s-stack>
                )}
            </s-stack>
        </s-section>
    );
}
