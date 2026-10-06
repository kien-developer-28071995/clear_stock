import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { useSetOverrides } from '@/features/forecasts/hooks/useForecasts';
import type { ForecastDetail } from '@/features/forecasts/types';
import { formatNumber } from '@/utils/format';
import { SaveBar } from '@/components/ui/SaveBar';

/**
 * Temporary adjustment of the sales rate (e.g. a promotion), optionally until a date.
 * Overrides win over the computed value and are shown in the explanation.
 */
export function AdjustForecastForm({ f }: { f: ForecastDetail }) {
    const { t } = useTranslation();
    const current = f.overrides.avg_daily_sales;
    const [value, setValue] = useState('');
    const [note, setNote] = useState('');
    const [until, setUntil] = useState('');
    const save = useSetOverrides(f.variant_id);

    const saved = { value: current ? String(current.value) : '', note: current?.note ?? '', until: current?.expires_at ?? '' };
    const discard = () => {
        setValue(saved.value);
        setNote(saved.note);
        setUntil(saved.until);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(discard, [current]);
    const dirty = JSON.stringify({ value, note, until }) !== JSON.stringify(saved);

    const reset = () =>
        save.mutate({ avg_daily_sales: null }, { onSuccess: () => shopify.toast.show(t('adjust.resetDone')) });
    // Clearing the rate and saving goes back to the automatic forecast.
    const submit = () =>
        value === ''
            ? reset()
            : save.mutate(
                  { avg_daily_sales: { value: Number(value), note: note || null, expires_at: until || null } },
                  { onSuccess: () => shopify.toast.show(t('adjust.saved')) },
              );

    return (
        <s-section heading={t('adjust.heading')}>
            <SaveBar id="adjust-forecast-save-bar" dirty={dirty} saving={save.isPending} onSave={submit} onDiscard={discard} />
            <s-stack gap="base">
                <s-paragraph>{t('adjust.intro', { rate: formatNumber(f.computed_avg, 2) })}</s-paragraph>
                <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr, 1fr" gap="base">
                    <s-number-field
                        label={t('product.sellsPerDay')}
                        min={0}
                        step={0.1}
                        value={value}
                        error={fieldError(save.error, 'avg_daily_sales.value')}
                        onInput={(e) => setValue(e.currentTarget.value)}
                    />
                    <s-date-field
                        label={t('adjust.until')}
                        details={t('adjust.untilHelp')}
                        value={until}
                        error={fieldError(save.error, 'avg_daily_sales.expires_at')}
                        onChange={(e) => setUntil(e.currentTarget.value)}
                    />
                </s-grid></s-query-container>
                <s-text-field
                    label={t('adjust.note')}
                    placeholder={t('adjust.notePlaceholder')}
                    value={note}
                    onInput={(e) => setNote(e.currentTarget.value)}
                />
                {current && (
                    <s-stack direction="inline">
                        <s-button onClick={reset} disabled={save.isPending || undefined}>
                            {t('adjust.reset')}
                        </s-button>
                    </s-stack>
                )}
            </s-stack>
        </s-section>
    );
}
