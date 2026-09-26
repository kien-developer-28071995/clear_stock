import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { formatMoney, formatNumber } from '@/utils/format';

export function SlowMovers({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const slow = dashboard.slow_movers;
    if (slow.count === 0) return null;

    return (
        <s-section heading={t('slow.heading')}>
            <s-stack gap="base">
                <s-paragraph>
                    {slow.value > 0
                        ? t('slow.summaryWithValue', { value: formatMoney(slow.value, dashboard.currency), count: slow.count, days: slow.days })
                        : t('slow.summary', { count: slow.count, days: slow.days })}{' '}
                    {t('slow.advice')}
                </s-paragraph>
                {slow.missing_cost > 0 && (
                    <s-text color="subdued">
                        {slow.missing_cost === slow.count ? t('slow.addCosts') : t('slow.missingCosts', { count: slow.missing_cost })}
                    </s-text>
                )}
                {/* Only products with a known cost are listed (the value needs the cost). */}
                {slow.top.length > 0 && (
                    <s-table>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                            <s-table-header format="numeric">{t('table.inStock')}</s-table-header>
                            <s-table-header format="numeric">{t('slow.daysOfStock')}</s-table-header>
                            <s-table-header format="currency">{t('slow.value')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {slow.top.map((row) => (
                                <s-table-row key={row.variant_id}>
                                    <s-table-cell>
                                        <s-link href={`/products/${row.variant_id}`}>{row.name}</s-link>
                                    </s-table-cell>
                                    <s-table-cell>{formatNumber(row.stock, 0)}</s-table-cell>
                                    <s-table-cell>{row.days_of_cover === null ? t('slow.noSales') : formatNumber(row.days_of_cover, 0)}</s-table-cell>
                                    <s-table-cell>{formatMoney(row.value, dashboard.currency)}</s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                )}
            </s-stack>
        </s-section>
    );
}
