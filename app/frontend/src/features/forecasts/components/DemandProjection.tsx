import { useTranslation } from 'react-i18next';
import type { ForecastDetail } from '@/features/forecasts/types';
import { formatDate, formatNumber } from '@/utils/format';

/** Expected sales over the next 30, 60 and 90 days, next to what stock on hand and on the way covers. */
export function DemandProjection({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    if (!f.projection) return null;

    return (
        <s-section heading={t('projection.heading')}>
            <s-stack gap="base">
                <s-query-container>
                    <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr 1fr, 1fr" gap="base">
                        {f.projection.map((p) => (
                            <s-stack key={p.days} gap="small-100">
                                <s-text color="subdued">{t('projection.period', { count: p.days, date: formatDate(p.until) })}</s-text>
                                <s-text type="strong">{t('product.units', { count: p.units, qty: formatNumber(p.units, 0) })}</s-text>
                                {p.shortfall > 0 ? (
                                    <s-text tone="critical">{t('projection.short', { count: p.shortfall, qty: formatNumber(p.shortfall, 0) })}</s-text>
                                ) : (
                                    <s-text color="subdued">{t('projection.covered')}</s-text>
                                )}
                            </s-stack>
                        ))}
                    </s-grid>
                </s-query-container>
                <s-text color="subdued">
                    {t(f.projection.some((p) => p.events) ? 'projection.basisEvents' : 'projection.basis', { rate: formatNumber(f.avg_daily_sales, 2) })}
                </s-text>
            </s-stack>
        </s-section>
    );
}
