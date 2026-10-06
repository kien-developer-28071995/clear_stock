import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { SnoozedItem } from '@/features/dashboard/types';
import { useSnooze } from '@/features/forecasts/hooks/useForecasts';
import { formatDate } from '@/utils/format';

const VISIBLE = 5;

/** Products put off with "Not now": when each comes back, and a way to bring it back today. */
export function SnoozedList({ items }: { items: SnoozedItem[] }) {
    const { t } = useTranslation();
    const snooze = useSnooze();
    const [all, setAll] = useState(false);
    if (items.length === 0) return null;
    const shown = all ? items : items.slice(0, VISIBLE);

    const bringBack = (item: SnoozedItem) =>
        snooze.mutate({ variant_ids: [item.variant_id], days: null }, { onSuccess: () => shopify.toast.show(t('snooze.back', { name: item.name })) });

    return (
        <s-section heading={t('snooze.listHeading', { count: items.length })} padding="none">
            {shown.map((item) => (
                <s-box key={item.variant_id} padding="small-200 base" borderWidth="small none none none" borderColor="base">
                    <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                        <s-stack gap="small-100">
                            <s-link href={`/products/${item.variant_id}`}>{item.name}</s-link>
                            <s-text color="subdued">{t('snooze.until', { date: formatDate(item.until) })}</s-text>
                        </s-stack>
                        <s-button
                            accessibilityLabel={t('snooze.bringBackNamed', { name: item.name })}
                            disabled={snooze.isPending || undefined}
                            onClick={() => bringBack(item)}
                        >
                            {t('snooze.bringBack')}
                        </s-button>
                    </s-stack>
                </s-box>
            ))}
            {items.length > VISIBLE && !all && (
                <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base">
                    <s-link onClick={() => setAll(true)}>{t('common.showAll', { count: items.length })}</s-link>
                </s-box>
            )}
        </s-section>
    );
}
