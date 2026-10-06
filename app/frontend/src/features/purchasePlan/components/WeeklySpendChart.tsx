import { useTranslation } from 'react-i18next';
import type { PurchasePlanWeek } from '@/features/purchasePlan/types';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';

// One series on a light admin surface: a single Polaris-blue hue, grid and labels in text tokens.
const BAR = '#2c6ecb';
const GRID = '#e3e3e3';
const HEIGHT = 160;

/**
 * Spend per week (units when no product has a cost). Hover a column for its numbers;
 * the table below the chart lists the same weeks per supplier and product.
 */
export function WeeklySpendChart({ weeks, currency }: { weeks: PurchasePlanWeek[]; currency: string | null }) {
    const { t } = useTranslation();
    const byCost = weeks.some((w) => w.cost > 0);
    const value = (w: PurchasePlanWeek) => (byCost ? w.cost : w.units);
    const max = Math.max(...weeks.map(value), 0);
    const label = (w: PurchasePlanWeek) =>
        byCost ? formatMoney(w.cost, currency) : t('purchasePlan.unitsValue', { count: w.units, units: formatNumber(w.units, 0) });
    const peak = weeks.reduce((best, w) => (value(w) > value(best) ? w : best), weeks[0]);

    return (
        <s-stack gap="small-300">
            <s-text color="subdued">{byCost ? t('purchasePlan.chartCost') : t('purchasePlan.chartUnits')}</s-text>
            <div role="list" aria-label={t('purchasePlan.chartLabel')} style={{ display: 'grid', gridTemplateColumns: `repeat(${weeks.length}, minmax(0, 1fr))`, gap: 2, alignItems: 'end', height: HEIGHT, borderBottom: `1px solid ${GRID}` }}>
                {weeks.map((w) => {
                    const h = max > 0 ? Math.max(value(w) > 0 ? 3 : 0, (value(w) / max) * (HEIGHT - 20)) : 0;
                    const title = t('purchasePlan.barTitle', { week: formatDate(w.start), value: label(w), count: w.orders });
                    return (
                        <div key={w.start} role="listitem" aria-label={title} title={title} style={{ height: '100%', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', cursor: 'default' }}>
                            {w === peak && max > 0 && (
                                <s-text color="subdued">
                                    <span style={{ display: 'block', textAlign: 'center', fontSize: 11, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{label(w)}</span>
                                </s-text>
                            )}
                            <div style={{ height: h, background: BAR, borderRadius: '4px 4px 0 0' }} />
                        </div>
                    );
                })}
            </div>
            <div aria-hidden style={{ display: 'grid', gridTemplateColumns: `repeat(${weeks.length}, minmax(0, 1fr))`, gap: 2 }}>
                {weeks.map((w, i) => (
                    <s-text key={w.start} color="subdued">
                        <span style={{ display: 'block', textAlign: 'center', fontSize: 11, whiteSpace: 'nowrap' }}>{i % 2 === 0 || weeks.length <= 8 ? formatDate(w.start) : ''}</span>
                    </s-text>
                ))}
            </div>
        </s-stack>
    );
}
