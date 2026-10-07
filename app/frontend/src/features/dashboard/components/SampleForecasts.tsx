import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Explanation } from '@/components/ui/Explanation';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { useSampleForecasts } from '@/features/dashboard/hooks/useDashboard';
import type { SampleForecast } from '@/features/dashboard/types';
import { formatNumber } from '@/utils/format';

function Row({ item, open, onToggle }: { item: SampleForecast; open: boolean; onToggle: () => void }) {
    const { t } = useTranslation();

    return (
        <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base">
            <s-stack gap="small-200">
                <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr auto, 1fr" gap="small-200" alignItems="center">
                    <s-stack gap="small-100">
                        <s-stack direction="inline" gap="small-200" alignItems="center">
                            <s-text type="strong">{t(`sample.products.${item.key}`)}</s-text>
                            <StatusBadge status={item.status} />
                        </s-stack>
                        <s-text color="subdued">
                            {t('sample.inStock', { stock: formatNumber(item.current_stock, 0) })} · {t('actions.sellsPerDay', { rate: formatNumber(item.avg_daily_sales, 1) })}
                        </s-text>
                    </s-stack>
                    <s-stack direction="inline" gap="base" alignItems="center">
                        {item.suggested_qty > 0 ? (
                            <s-text type="strong">{t('actions.order', { qty: formatNumber(item.suggested_qty, 0) })}</s-text>
                        ) : (
                            <s-text color="subdued">{t('actions.nothingMore')}</s-text>
                        )}
                        <s-button variant="tertiary" onClick={onToggle}>{open ? t('sample.hide') : t('sample.why')}</s-button>
                    </s-stack>
                </s-grid></s-query-container>
                {open && <Explanation lines={item.explanation_lines} />}
            </s-stack>
        </s-box>
    );
}

/**
 * What the app does, on a made-up catalog: shown on Home while the shop has no forecasts of
 * its own. The numbers come from the real calculator with the shop's lead time (backend
 * SampleForecasts); a failed request shows nothing, the page works without it.
 */
export function SampleForecasts() {
    const { t } = useTranslation();
    const { data } = useSampleForecasts();
    const [open, setOpen] = useState<string | null | undefined>(undefined);

    if (!data || data.length === 0) return null;
    // The first product starts open: the explanation is the point.
    const shown = open === undefined ? data[0].key : open;

    return (
        <s-section padding="none">
            <s-box padding="base">
                <s-stack gap="small-200">
                    <s-stack direction="inline" gap="small-200" alignItems="center">
                        <s-heading>{t('sample.heading')}</s-heading>
                        <s-badge tone="info">{t('sample.badge')}</s-badge>
                    </s-stack>
                    <s-paragraph>{t('sample.intro')}</s-paragraph>
                </s-stack>
            </s-box>
            {data.map((item) => (
                <Row key={item.key} item={item} open={shown === item.key} onToggle={() => setOpen(shown === item.key ? null : item.key)} />
            ))}
        </s-section>
    );
}
