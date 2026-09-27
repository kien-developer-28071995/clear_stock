import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { formatMoney, formatNumber } from '@/utils/format';

/** Discontinued products still in stock: what is left to sell through (never reordered). */
export function DiscontinuedStock({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const d = dashboard.discontinued;
    if (!d || d.count === 0) return null;

    return (
        <s-section heading={t('discontinued.heading')}>
            <s-stack gap="small-200">
                <s-paragraph>
                    {d.value > 0
                        ? t('discontinued.summaryWithValue', { count: d.count, units: formatNumber(d.units, 0), value: formatMoney(d.value, dashboard.currency) })
                        : t('discontinued.summaryUnits', { count: d.count, units: formatNumber(d.units, 0) })}
                </s-paragraph>
                {d.missing_cost > 0 && <s-text color="subdued">{t('slow.missingCosts', { count: d.missing_cost })}</s-text>}
                <s-link href="/products?status=discontinued">{t('discontinued.viewAll')}</s-link>
            </s-stack>
        </s-section>
    );
}
