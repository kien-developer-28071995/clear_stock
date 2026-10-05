import { useTranslation } from 'react-i18next';
import { useFeature } from '@/hooks/useEntitlements';

/** "N products have no cost" with a link to enter them in the app. */
export function MissingCostNote({ count, all = false, text }: { count: number; all?: boolean; text?: string }) {
    const { t } = useTranslation();
    const costs = useFeature('costs');
    if (count <= 0) return null;

    return (
        <s-text color="subdued">
            {text ?? (all ? t('slow.addCosts') : t('slow.missingCosts', { count }))} {costs && <s-link href="/costs">{t('costs.addLink')}</s-link>}
        </s-text>
    );
}
