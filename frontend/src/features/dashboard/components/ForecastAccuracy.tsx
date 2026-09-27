import { useTranslation } from 'react-i18next';
import { useAccuracy } from '@/features/dashboard/hooks/useDashboard';
import { useFeature } from '@/hooks/useEntitlements';
import { formatDate, formatNumber } from '@/utils/format';

const percent = (ratio: number | null) => (ratio === null ? '—' : `${formatNumber(ratio * 100, 0)}%`);

/** Forecasts of a few weeks ago next to what really sold: the proof behind "every number can be checked". */
export function ForecastAccuracy() {
    const { t } = useTranslation();
    const exists = useFeature('accuracy');
    const query = useAccuracy(exists);
    const report = query.data;
    if (!exists || !report) return null;

    const latest = report.latest;

    return (
        <s-section heading={t('accuracy.heading')}>
            <s-stack gap="base">
                {!report.available || !latest ? (
                    <s-paragraph>
                        {report.first_result_on
                            ? t('accuracy.collecting', { date: formatDate(report.first_result_on), count: report.horizon_days })
                            : t('accuracy.collectingSoon', { count: report.horizon_days })}
                    </s-paragraph>
                ) : latest.accuracy === null ? (
                    <s-paragraph>{t('accuracy.noSales', { from: formatDate(latest.week_start), to: formatDate(latest.week_end) })}</s-paragraph>
                ) : (
                    <>
                        <s-paragraph>
                            {t('accuracy.summary', {
                                accuracy: percent(latest.accuracy),
                                count: latest.products,
                                from: formatDate(latest.week_start),
                                to: formatDate(latest.week_end),
                            })}{' '}
                            {latest.bias !== null && Math.abs(latest.bias) >= 0.05
                                ? t(latest.bias > 0 ? 'accuracy.biasHigh' : 'accuracy.biasLow', { percent: percent(Math.abs(latest.bias)) })
                                : t('accuracy.biasNone')}
                        </s-paragraph>
                        <s-text color="subdued">{t('accuracy.how', { count: report.min_in_stock_days })}</s-text>
                        {latest.by_source.override && latest.by_source.computed && (
                            <s-text color="subdued">
                                {t('accuracy.bySource', {
                                    computed: percent(latest.by_source.computed.accuracy),
                                    override: percent(latest.by_source.override.accuracy),
                                    count: latest.by_source.override.products,
                                })}
                            </s-text>
                        )}
                        {report.trend.length > 1 && (
                            <s-stack direction="inline" gap="small-200">
                                <s-text color="subdued">{t('accuracy.trend')}</s-text>
                                {report.trend.map((w) => (
                                    <s-badge key={w.week_start} tone="neutral">
                                        {formatDate(w.week_start)}: {percent(w.accuracy)}
                                    </s-badge>
                                ))}
                            </s-stack>
                        )}
                        {latest.top_misses.length > 0 && (
                            <s-table>
                                <s-table-header-row>
                                    <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                                    <s-table-header format="numeric">{t('accuracy.forecast')}</s-table-header>
                                    <s-table-header format="numeric">{t('accuracy.actual')}</s-table-header>
                                    <s-table-header format="numeric">{t('accuracy.difference')}</s-table-header>
                                </s-table-header-row>
                                <s-table-body>
                                    {latest.top_misses.map((row) => (
                                        <s-table-row key={row.variant_id}>
                                            <s-table-cell>
                                                <s-link href={`/products/${row.variant_id}`}>{row.name}</s-link>
                                                {row.source === 'override' && <> <s-badge tone="info">{t('product.adjustedByYou')}</s-badge></>}
                                            </s-table-cell>
                                            <s-table-cell>{t('accuracy.perDay', { value: formatNumber(row.predicted, 2) })}</s-table-cell>
                                            <s-table-cell>{t('accuracy.perDay', { value: formatNumber(row.actual, 2) })}</s-table-cell>
                                            <s-table-cell>{`${row.error_units > 0 ? '+' : ''}${formatNumber(row.error_units, 0)}`}</s-table-cell>
                                        </s-table-row>
                                    ))}
                                </s-table-body>
                            </s-table>
                        )}
                    </>
                )}
            </s-stack>
        </s-section>
    );
}
