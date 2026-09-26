import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { fieldError } from '@/lib/http';
import { useSetOverrides } from '@/features/forecasts/hooks/useForecasts';
import type { ForecastDetail } from '@/features/forecasts/types';
import { formatNumber } from '@/utils/format';

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

    useEffect(() => {
        setValue(current ? String(current.value) : '');
        setNote(current?.note ?? '');
        setUntil(current?.expires_at ?? '');
    }, [current]);

    const submit = () =>
        save.mutate(
            { avg_daily_sales: { value: Number(value), note: note || null, expires_at: until || null } },
            { onSuccess: () => shopify.toast.show(t('adjust.saved')) },
        );
    const reset = () =>
        save.mutate({ avg_daily_sales: null }, { onSuccess: () => shopify.toast.show(t('adjust.resetDone')) });

    return (
        <s-section heading={t('adjust.heading')}>
            <s-stack gap="base">
                <s-paragraph>{t('adjust.intro', { rate: formatNumber(f.computed_avg, 2) })}</s-paragraph>
                <s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr 1fr, 1fr" gap="base">
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
                </s-grid>
                <s-text-field
                    label={t('adjust.note')}
                    placeholder={t('adjust.notePlaceholder')}
                    value={note}
                    onInput={(e) => setNote(e.currentTarget.value)}
                />
                <s-stack direction="inline" gap="small-200">
                    <s-button variant="primary" onClick={submit} disabled={value === '' || undefined} loading={save.isPending || undefined}>
                        {t('adjust.save')}
                    </s-button>
                    {current && (
                        <s-button onClick={reset} disabled={save.isPending || undefined}>
                            {t('adjust.reset')}
                        </s-button>
                    )}
                </s-stack>
            </s-stack>
        </s-section>
    );
}
