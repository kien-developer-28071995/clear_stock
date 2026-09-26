import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { AbcBadge } from '@/features/forecasts/components/AbcBadge';
import { formatMoney, formatNumber } from '@/utils/format';

const CLASSES = ['A', 'B', 'C'] as const;

/** ABC classes: how many products make most of the revenue, and where the stock money sits. */
export function AbcSummary({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const abc = dashboard.abc;
    if (!abc) return null;
    const classified = CLASSES.reduce((n, c) => n + abc.classes[c].count, 0);
    if (classified === 0) return null;

    const totalStock = CLASSES.reduce((n, c) => n + abc.classes[c].stock_value, 0);
    const pct = (v: number) => `${formatNumber(v * 100, 0)}%`;

    return (
        <s-section heading={t('abc.heading')}>
            <s-stack gap="base">
                <s-paragraph>
                    {t('abc.intro', { count: abc.days, a: pct(abc.thresholds.a), b: pct(abc.thresholds.b) })}
                </s-paragraph>
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('abc.column')}</s-table-header>
                        <s-table-header format="numeric">{t('abc.products')}</s-table-header>
                        <s-table-header format="numeric">{t('abc.revenueShare')}</s-table-header>
                        <s-table-header format="currency">{t('abc.revenue')}</s-table-header>
                        {/* Without any unit costs the value would read 0: hide it (the note below says why). */}
                        {totalStock > 0 && <s-table-header format="currency">{t('abc.stockValue')}</s-table-header>}
                    </s-table-header-row>
                    <s-table-body>
                        {CLASSES.map((c) => {
                            const row = abc.classes[c];
                            return (
                                <s-table-row key={c}>
                                    <s-table-cell>
                                        <s-stack direction="inline" gap="small-200" alignItems="center">
                                            <AbcBadge abc={c} />
                                            {row.count > 0 ? <s-link href={`/products?abc=${c}`}>{t(`abc.advice${c}`)}</s-link> : <s-text>{t(`abc.advice${c}`)}</s-text>}
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>{formatNumber(row.count, 0)}</s-table-cell>
                                    <s-table-cell>{pct(row.revenue_share)}</s-table-cell>
                                    <s-table-cell>{formatMoney(row.revenue, dashboard.currency)}</s-table-cell>
                                    {totalStock > 0 && (
                                        <s-table-cell>
                                            {formatMoney(row.stock_value, dashboard.currency)} ({pct(row.stock_value / totalStock)})
                                        </s-table-cell>
                                    )}
                                </s-table-row>
                            );
                        })}
                    </s-table-body>
                </s-table>
                {abc.unclassified > 0 && <s-text color="subdued">{t('abc.unclassified', { count: abc.unclassified })}</s-text>}
                {abc.missing_cost > 0 && <s-text color="subdued">{t('slow.missingCosts', { count: abc.missing_cost })}</s-text>}
            </s-stack>
        </s-section>
    );
}
