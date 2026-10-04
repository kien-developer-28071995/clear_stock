import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ForecastDetail } from '@/features/forecasts/types';
import { useSetLocationMinimums } from '@/features/forecasts/hooks/useForecasts';
import { ConfidenceBadge } from '@/components/ui/StatusBadge';
import { Explanation } from '@/components/ui/Explanation';
import { formatDate, formatNumber } from '@/utils/format';

/** Growth: stock and forecast per location, each with its own reasoning. */
export function LocationForecasts({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState<number | null>(null);
    // Manual minimum per location: the values being edited (location id => text), saved together.
    const [mins, setMins] = useState<Record<number, string>>({});
    const saveMins = useSetLocationMinimums(f.variant_id);
    if (!f.locations || f.locations.length < 2) return null;

    const forecasted = f.locations.some((l) => l.forecast);
    const opened = f.locations.find((l) => l.location_id === open);

    return (
        <s-section heading={t('locations.heading')}>
            <s-stack gap="base">
                {!forecasted && (
                    <s-text color="subdued">{t('locations.pending')}</s-text>
                )}
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('locations.location')}</s-table-header>
                        <s-table-header format="numeric">{t('table.inStock')}</s-table-header>
                        <s-table-header format="numeric">{t('table.perDay')}</s-table-header>
                        <s-table-header format="numeric">{t('table.daysLeft')}</s-table-header>
                        <s-table-header>{t('table.orderBy')}</s-table-header>
                        <s-table-header format="numeric">{t('table.suggestedOrder')}</s-table-header>
                        <s-table-header>{t('table.confidence')}</s-table-header>
                        <s-table-header>{t('locations.minimum')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {f.locations.map((l) => (
                            <s-table-row key={l.location_id}>
                                <s-table-cell>
                                    {l.forecast ? (
                                        <s-link onClick={() => setOpen(open === l.location_id ? null : l.location_id)}>{l.location}</s-link>
                                    ) : (
                                        l.location
                                    )}
                                </s-table-cell>
                                <s-table-cell>{formatNumber(l.available, 0)}</s-table-cell>
                                <s-table-cell>{l.forecast ? formatNumber(l.forecast.avg_daily_sales, 2) : '—'}</s-table-cell>
                                <s-table-cell>
                                    {!l.forecast ? '—' : l.forecast.days_of_cover === null ? '∞' : formatNumber(l.forecast.days_of_cover, 0)}
                                </s-table-cell>
                                <s-table-cell>{l.forecast && l.forecast.avg_daily_sales > 0 ? formatDate(l.forecast.reorder_date) : '—'}</s-table-cell>
                                <s-table-cell>{l.forecast ? formatNumber(l.forecast.suggested_qty, 0) : '—'}</s-table-cell>
                                <s-table-cell>{l.forecast ? <ConfidenceBadge confidence={l.forecast.confidence} /> : '—'}</s-table-cell>
                                <s-table-cell>
                                    <s-box maxInlineSize="110px">
                                        <s-number-field
                                            label={t('locations.minimumAt', { location: l.location })}
                                            labelAccessibilityVisibility="exclusive"
                                            min={0}
                                            placeholder={t('productSettings.fromForecastShort')}
                                            value={mins[l.location_id] ?? l.min_stock?.toString() ?? ''}
                                            onInput={(e) => setMins({ ...mins, [l.location_id]: e.currentTarget.value })}
                                        />
                                    </s-box>
                                </s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
                {opened?.forecast && opened.forecast.explanation_lines.length > 0 && (
                    <s-box padding="base" background="subdued" borderRadius="base">
                        <s-stack gap="small-200">
                            <s-text type="strong">{t('locations.why', { location: opened.location })}</s-text>
                            <Explanation lines={opened.forecast.explanation_lines} />
                        </s-stack>
                    </s-box>
                )}
                {Object.keys(mins).length > 0 && (
                    <s-stack direction="inline" gap="small-200">
                        <s-button
                            variant="primary"
                            loading={saveMins.isPending || undefined}
                            onClick={() =>
                                saveMins.mutate(
                                    Object.entries(mins).map(([id, value]) => ({ location_id: Number(id), min_stock: value.trim() === '' ? null : Number(value) })),
                                    { onSuccess: () => { setMins({}); shopify.toast.show(t('common.saved')); } },
                                )
                            }
                        >
                            {t('locations.saveMinimums')}
                        </s-button>
                        <s-button variant="tertiary" onClick={() => setMins({})}>{t('common.cancel')}</s-button>
                    </s-stack>
                )}
                {forecasted && <s-text color="subdued">{t('locations.hint')}</s-text>}
            </s-stack>
        </s-section>
    );
}
