import type { ForecastDetail } from '@/features/forecasts/types';
import { useTranslation } from 'react-i18next';
import { Explanation } from '@/components/ui/Explanation';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { formatNumber } from '@/utils/format';

const SEASONALITY_REASONS = ['not_enough_history', 'out_of_stock_last_year', 'too_few_sales_last_year', 'no_significant_change'] as const;
type SeasonalityReason = (typeof SEASONALITY_REASONS)[number];

/** The forecast's reasoning: plain sentences first, the underlying numbers below. */
export function WhyThisNumber({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    const e = f.explanation;

    if (e === null) {
        return (
            <s-section heading={t('why.heading')}>
                <UpgradePrompt plan="starter">{t('why.locked')}</UpgradePrompt>
            </s-section>
        );
    }

    return (
        <s-section heading={t('why.heading')}>
            <s-stack gap="base">
                <Explanation lines={f.explanation_lines} />

                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('why.period')}</s-table-header>
                        <s-table-header format="numeric">{t('why.unitsSold')}</s-table-header>
                        <s-table-header format="numeric">{t('why.daysInStock')}</s-table-header>
                        <s-table-header format="numeric">{t('why.excludedDays')}</s-table-header>
                        <s-table-header format="numeric">{t('why.perDay')}</s-table-header>
                        <s-table-header format="numeric">{t('why.weight')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {e.windows.map((w) => (
                            <s-table-row key={w.days}>
                                <s-table-cell>{t('why.lastDays', { count: w.days })}</s-table-cell>
                                <s-table-cell>{formatNumber(w.units, 0)}</s-table-cell>
                                <s-table-cell>{w.in_stock_days}</s-table-cell>
                                <s-table-cell>{w.excluded_out_of_stock_days}</s-table-cell>
                                <s-table-cell>{w.avg === null ? t('why.notEnoughData') : formatNumber(w.avg, 2)}</s-table-cell>
                                <s-table-cell>{Math.round(w.weight * 100)}%</s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>

                <s-text color="subdued">
                    {e.seasonality.applied
                        ? t('why.seasonalityApplied', { factor: formatNumber(e.seasonality.factor, 2), days: e.seasonality.horizon_days })
                        : t('why.seasonalityNotApplied', {
                              reason: SEASONALITY_REASONS.includes(e.seasonality.reason as SeasonalityReason)
                                  ? t(`why.seasonalityReasons.${e.seasonality.reason as SeasonalityReason}`)
                                  : e.seasonality.reason,
                          })}
                </s-text>
            </s-stack>
        </s-section>
    );
}
