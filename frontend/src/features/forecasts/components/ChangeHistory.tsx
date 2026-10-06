import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { useChanges } from '@/features/forecasts/hooks/useForecasts';
import type { ChangeEntry } from '@/features/forecasts/types';
import { FORECAST_PROFILES } from '@/features/forecasts/types';
import { formatDate, formatDateTime, formatNumber } from '@/utils/format';

const SWITCHES = ['alerts_muted', 'discontinued'];
const DATES = ['snoozed_until'];
const TEXT = ['supplier', 'reference', 'supplier_sku'];

/** Locale keys cannot contain a dot: `override.avg_daily_sales` is `override_avg_daily_sales`. */
const fieldKey = (field: string) => field.replace('.', '_');

/** What the merchant changed on this product, newest first: old value next to new. */
export function ChangeHistory({ variantId }: { variantId: number }) {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useChanges(variantId, true);

    const value = (c: ChangeEntry, v: string | null): string => {
        if (v === null) return t('history.empty');
        if (SWITCHES.includes(c.field)) return t(v === '1' ? 'history.on' : 'history.off');
        if (DATES.includes(c.field)) return formatDate(v);
        if (c.field === 'forecast_profile') {
            const profile = FORECAST_PROFILES.find((p) => p === v);
            return profile ? t(`forecastProfile.${profile}`) : v;
        }
        if (TEXT.includes(c.field) || Number.isNaN(Number(v))) return v;
        return formatNumber(Number(v), 2);
    };

    return (
        <s-section heading={t('history.heading')}>
            {error ? (
                <ErrorBanner error={error} onRetry={() => refetch()} />
            ) : isPending ? (
                <s-spinner accessibilityLabel={t('common.loading')} />
            ) : data.length === 0 ? (
                <s-paragraph>{t('history.none')}</s-paragraph>
            ) : (
                <s-stack gap="base">
                    <s-table>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('history.what')}</s-table-header>
                            <s-table-header listSlot="labeled">{t('history.from')}</s-table-header>
                            <s-table-header listSlot="labeled">{t('history.to')}</s-table-header>
                            <s-table-header listSlot="secondary">{t('history.when')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data.map((c) => (
                                <s-table-row key={c.id}>
                                    <s-table-cell>
                                        <s-stack gap="small-100">
                                            <s-text>{t(`history.fields.${fieldKey(c.field)}`, { defaultValue: c.field })}</s-text>
                                            {c.source !== 'app' && <s-text color="subdued">{t(`history.sources.${c.source}`)}</s-text>}
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>{value(c, c.old)}</s-table-cell>
                                    <s-table-cell>{value(c, c.new)}</s-table-cell>
                                    <s-table-cell>{formatDateTime(c.at)}</s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                    <s-text color="subdued">{t('history.kept')}</s-text>
                </s-stack>
            )}
        </s-section>
    );
}
