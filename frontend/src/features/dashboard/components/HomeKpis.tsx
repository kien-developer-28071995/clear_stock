import { useTranslation } from 'react-i18next';
import type { Dashboard } from '@/features/dashboard/types';
import { formatMoney, formatNumber } from '@/utils/format';

function Kpi({ label, value, hint, href, tone }: { label: string; value: string; hint: string; href: string; tone?: 'critical' | 'warning' }) {
    return (
        <s-clickable href={href} border="base" borderRadius="base" padding="base" background="base">
            <s-stack gap="small-200">
                <s-text color="subdued">{label}</s-text>
                <s-heading>
                    <s-text tone={tone}>{value}</s-text>
                </s-heading>
                <s-text color="subdued">{hint}</s-text>
            </s-stack>
        </s-clickable>
    );
}

/** Four numbers that say how the stock is doing; each opens the page with the details. */
export function HomeKpis({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const { counts, actions, slow_movers: slow, overstock } = dashboard;
    const tiedUp = slow.value + overstock.value;

    return (
        <s-grid gridTemplateColumns="@container (inline-size > 600px) 1fr 1fr 1fr 1fr, 1fr 1fr" gap="base">
            <Kpi
                label={t('status.out_of_stock')}
                value={formatNumber(counts.out_of_stock, 0)}
                hint={t('home.kpi.outOfStock')}
                href="/reorder"
                tone={counts.out_of_stock > 0 ? 'critical' : undefined}
            />
            <Kpi
                label={t('status.reorder_now')}
                value={formatNumber(counts.reorder_now, 0)}
                hint={t('home.kpi.reorderNow')}
                href="/reorder"
                tone={counts.reorder_now > 0 ? 'warning' : undefined}
            />
            <Kpi label={t('home.kpi.thisWeekLabel')} value={formatNumber(actions.this_week.length, 0)} hint={t('home.kpi.thisWeek')} href="/reorder" />
            {/* Money tied up in stock: slow movers + overstock. */}
            <Kpi
                label={t('home.kpi.tiedUpLabel')}
                value={tiedUp > 0 ? formatMoney(tiedUp, dashboard.currency) : formatNumber(slow.count + overstock.count, 0)}
                hint={t('home.kpi.tiedUpHint', { slow: slow.count, overstock: overstock.count })}
                href="/insights"
            />
        </s-grid>
    );
}
