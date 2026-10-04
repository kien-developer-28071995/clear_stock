import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MissingCostNote } from '@/components/ui/MissingCostNote';
import { useStockHistory } from '@/features/reports/hooks/useReports';
import { useShop } from '@/features/shop/hooks/useShop';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';

// Same single hue as the other charts of the app.
const LINE = '#2c6ecb';
const GRID = '#e3e3e3';
const WIDTH = 600;
const HEIGHT = 160;
const PERIODS = [30, 90, 180, 365] as const;
const DEFAULT_PERIOD = 90;

/**
 * Inventory value at cost over time (units when no product has a cost). Recorded once a day
 * from the day the app is installed: Shopify keeps no stock history to start from.
 */
export function StockHistoryChart() {
    const { t } = useTranslation();
    const [days, setDays] = useState<number>(DEFAULT_PERIOD);
    const { data } = useStockHistory(days);
    const currency = useShop().data?.currency ?? null;
    if (!data || !data.latest) return null;

    const byValue = data.points.some((p) => p.value > 0);
    const y = (p: { units: number; value: number }) => (byValue ? p.value : p.units);
    const show = (p: { units: number; value: number }) =>
        byValue ? formatMoney(p.value, currency) : t('stockHistory.units', { count: p.units, units: formatNumber(p.units, 0) });
    const max = Math.max(...data.points.map(y), 1);
    // Points sit at their real dates, so a gap in the recording shows as a longer segment.
    const start = Date.parse(data.points[0].date);
    const span = Math.max(1, Date.parse(data.latest.date) - start);
    const xy = data.points.map((p) => [((Date.parse(p.date) - start) / span) * WIDTH, HEIGHT - 4 - (y(p) / max) * (HEIGHT - 16)] as const);
    const line = xy.map(([px, py]) => `${px.toFixed(1)},${py.toFixed(1)}`).join(' ');
    const change = data.change;

    return (
        <s-section heading={t('stockHistory.heading')}>
            <s-stack gap="base">
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    {byValue && <s-text type="strong">{show(data.latest)}</s-text>}
                    <s-text color="subdued">
                        {t('stockHistory.inStock', { count: data.latest.products_in_stock, units: formatNumber(data.latest.units, 0) })}
                    </s-text>
                    {change && (byValue ? change.value !== 0 : change.units !== 0) && (
                        <s-badge tone="neutral">
                            {t((byValue ? change.value : change.units) > 0 ? 'stockHistory.up' : 'stockHistory.down', {
                                amount: byValue ? formatMoney(Math.abs(change.value), currency) : formatNumber(Math.abs(change.units), 0),
                                date: formatDate(change.from),
                            })}
                        </s-badge>
                    )}
                </s-stack>
                <MissingCostNote count={data.latest.products_missing_cost} all={data.latest.products_missing_cost === data.latest.products_in_stock} />
                {data.points.length > 1 ? (
                    <>
                        <svg
                            viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                            preserveAspectRatio="none"
                            role="img"
                            aria-label={t('stockHistory.chartLabel', { from: formatDate(data.points[0].date), to: formatDate(data.latest.date), first: show(data.points[0]), last: show(data.latest) })}
                            style={{ width: '100%', height: HEIGHT, display: 'block', borderBottom: `1px solid ${GRID}` }}
                        >
                            <polygon points={`0,${HEIGHT} ${line} ${WIDTH},${HEIGHT}`} fill={LINE} opacity={0.12} />
                            <polyline points={line} fill="none" stroke={LINE} strokeWidth={2} vectorEffect="non-scaling-stroke" />
                            {xy.map(([px, py], i) => (
                                <circle key={data.points[i].date} cx={px} cy={py} r={6} fill="transparent">
                                    <title>{`${formatDate(data.points[i].date)}: ${show(data.points[i])}`}</title>
                                </circle>
                            ))}
                        </svg>
                        <s-stack direction="inline" justifyContent="space-between">
                            <s-text color="subdued">{formatDate(data.points[0].date)}</s-text>
                            <s-text color="subdued">{formatDate(data.latest.date)}</s-text>
                        </s-stack>
                    </>
                ) : (
                    <s-text color="subdued">{t('stockHistory.collecting', { date: formatDate(data.latest.date) })}</s-text>
                )}
                {(data.points.length > 1 || days !== DEFAULT_PERIOD) && (
                    <s-box maxInlineSize="200px">
                        <s-select label={t('stockHistory.periodLabel')} value={String(days)} onChange={(e) => setDays(Number(e.currentTarget.value))}>
                            {PERIODS.map((p) => (
                                <s-option key={p} value={String(p)}>{t('stockHistory.period', { count: p })}</s-option>
                            ))}
                        </s-select>
                    </s-box>
                )}
                <s-text color="subdued">{t('stockHistory.how')}</s-text>
            </s-stack>
        </s-section>
    );
}
