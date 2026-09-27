import { useTranslation } from 'react-i18next';
import { MissingCostNote } from '@/components/ui/MissingCostNote';
import type { Dashboard } from '@/features/dashboard/types';
import { formatMoney, formatNumber } from '@/utils/format';

/** Products still selling but holding clearly more than needed, with the money tied up in the excess. */
export function Overstock({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const over = dashboard.overstock;
    if (over.count === 0) return null;

    return (
        <s-section heading={t('overstock.heading')}>
            <s-stack gap="base">
                <s-paragraph>
                    {over.value > 0
                        ? t('overstock.summaryWithValue', { value: formatMoney(over.value, dashboard.currency), units: formatNumber(over.units, 0), count: over.count })
                        : t('overstock.summary', { units: formatNumber(over.units, 0), count: over.count })}{' '}
                    {t('overstock.advice')}
                </s-paragraph>
                <MissingCostNote count={over.missing_cost} />
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                        <s-table-header format="numeric">{t('table.inStock')}</s-table-header>
                        <s-table-header format="numeric">{t('overstock.target')}</s-table-header>
                        <s-table-header format="numeric">{t('overstock.excess')}</s-table-header>
                        <s-table-header format="currency">{t('slow.value')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {over.top.map((row) => (
                            <s-table-row key={row.variant_id}>
                                <s-table-cell>
                                    <s-link href={`/products/${row.variant_id}`}>{row.name}</s-link>
                                </s-table-cell>
                                <s-table-cell>{formatNumber(row.stock, 0)}</s-table-cell>
                                <s-table-cell>{formatNumber(row.target, 0)}</s-table-cell>
                                <s-table-cell>{formatNumber(row.excess, 0)}</s-table-cell>
                                <s-table-cell>{row.value === null ? '—' : formatMoney(row.value, dashboard.currency)}</s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
                <s-link href="/products?status=overstock">{t('overstock.seeAll', { count: over.count })}</s-link>
            </s-stack>
        </s-section>
    );
}
