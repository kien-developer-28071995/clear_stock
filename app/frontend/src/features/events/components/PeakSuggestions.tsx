import { useTranslation } from 'react-i18next';
import { useCreateSalesEvent, usePeakSuggestions } from '@/features/events/hooks/useSalesEvents';
import type { PeakSuggestion } from '@/features/events/types';
import { formatDate, formatNumber } from '@/utils/format';

/**
 * A coming peak (Black Friday weekend) the shop has no event for, with the rise it saw last
 * year. One click adds it as an event; the merchant can change the number afterwards. A failed
 * request shows nothing: the page works without it.
 */
export function PeakSuggestions() {
    const { t } = useTranslation();
    const { data } = usePeakSuggestions();
    const create = useCreateSalesEvent();

    if (!data || data.length === 0) return null;

    const add = (s: PeakSuggestion) =>
        create.mutate(
            { name: `${t(`events.peaks.${s.key}`)} ${s.starts_on.slice(0, 4)}`, starts_on: s.starts_on, ends_on: s.ends_on, multiplier: s.multiplier, applies_to: 'all', repeats_yearly: false },
            { onSuccess: () => shopify.toast.show(t('events.added')), onError: () => shopify.toast.show(t('errors.generic'), { isError: true }) },
        );

    return (
        <>
            {data.map((s) => (
                <s-section key={s.key} heading={t('events.suggestion.heading', { name: t(`events.peaks.${s.key}`), from: formatDate(s.starts_on), to: formatDate(s.ends_on) })}>
                    <s-stack gap="base">
                        <s-paragraph>
                            {t('events.suggestion.body', {
                                units: formatNumber(s.last_year.units_per_day, 1),
                                usual: formatNumber(s.last_year.usual_per_day, 1),
                                change: `+${formatNumber((s.multiplier - 1) * 100, 0)}%`,
                            })}
                        </s-paragraph>
                        <s-stack direction="inline">
                            <s-button onClick={() => add(s)} loading={create.isPending || undefined}>
                                {t('events.suggestion.add', { change: `+${formatNumber((s.multiplier - 1) * 100, 0)}%` })}
                            </s-button>
                        </s-stack>
                    </s-stack>
                </s-section>
            ))}
        </>
    );
}
