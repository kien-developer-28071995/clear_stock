import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { formatMoney, formatNumber } from '@/utils/format';

/** Sales missed while out of stock in the last 30 days: the cost of running out. */
export function LostSales({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const lost = dashboard.lost_sales;
    if (!lost || lost.count === 0) return null;

    return (
        <s-section heading={t('lostSales.heading')}>
            <s-stack gap="base">
                <s-paragraph>
                    {lost.revenue > 0
                        ? t('lostSales.summaryWithRevenue', { revenue: formatMoney(lost.revenue, dashboard.currency), units: formatNumber(lost.units, 0), count: lost.count, days: lost.days })
                        : t('lostSales.summary', { units: formatNumber(lost.units, 0), count: lost.count, days: lost.days })}{' '}
                    {t('lostSales.how')}
                </s-paragraph>
                {lost.missing_price > 0 && <s-text color="subdued">{t('lostSales.missingPrice', { count: lost.missing_price })}</s-text>}
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                        <s-table-header format="numeric">{t('lostSales.daysOut')}</s-table-header>
                        <s-table-header format="numeric">{t('lostSales.units')}</s-table-header>
                        <s-table-header format="currency">{t('lostSales.revenue')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {lost.top.map((row) => (
                            <s-table-row key={row.variant_id}>
                                <s-table-cell>
                                    <s-link href={`/products/${row.variant_id}`}>{row.name}</s-link>
                                </s-table-cell>
                                <s-table-cell>{formatNumber(row.out_of_stock_days, 0)}</s-table-cell>
                                <s-table-cell>{formatNumber(row.units, 0)}</s-table-cell>
                                <s-table-cell>{row.revenue === null ? '—' : formatMoney(row.revenue, dashboard.currency)}</s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
            </s-stack>
        </s-section>
    );
}
