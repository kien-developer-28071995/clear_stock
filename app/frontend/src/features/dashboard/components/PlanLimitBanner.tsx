import { useTranslation } from 'react-i18next';
import { useDismissed } from '@/hooks/useDismissed';

/** Free plan forecasts the best sellers only: say so, with a way out. */
export function PlanLimitBanner({ counts }: { counts: { total: number; tracked: number } }) {
    const { t } = useTranslation();
    const [dismissed, dismiss] = useDismissed('plan-limit');
    const missing = counts.tracked - counts.total;
    if (missing <= 0 || dismissed) return null;

    return (
        <s-banner tone="info" heading={t('planLimit.heading', { count: counts.total })} dismissible onDismiss={dismiss}>
            <s-paragraph>{t('planLimit.body', { total: counts.total, count: missing })}</s-paragraph>
            <s-button slot="secondary-actions" href="/plans">{t('upgrade.seePlans')}</s-button>
        </s-banner>
    );
}
