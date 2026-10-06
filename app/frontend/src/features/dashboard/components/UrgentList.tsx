import { useTranslation } from 'react-i18next';
import { translateCode } from '@/i18n/codes';
import type { ActionItem, Dashboard } from '@/features/dashboard/types';
import { formatNumber } from '@/utils/format';

const SHOWN = 5;

function Row({ item }: { item: ActionItem }) {
    const { t } = useTranslation();

    return (
        <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base">
            <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 500px) 1fr auto, 1fr" gap="small-200" alignItems="center">
                <s-stack gap="small-100">
                    <s-link href={`/products/${item.variant_id}`}>{item.name}</s-link>
                    <s-text color="subdued">
                        {item.reason ? translateCode('explanation', item.reason) : t('actions.sellsPerDay', { rate: formatNumber(item.avg_daily_sales, 1) })}
                    </s-text>
                </s-stack>
                <s-stack direction="inline" gap="small-200" alignItems="center">
                    {item.current_stock <= 0 && <s-badge tone="critical">{t('status.out_of_stock')}</s-badge>}
                    {item.suggested_qty > 0 ? (
                        <s-text type="strong">{t('actions.order', { qty: formatNumber(item.suggested_qty, 0) })}</s-text>
                    ) : (
                        <s-text color="subdued">{t('actions.nothingMore')}</s-text>
                    )}
                </s-stack>
            </s-grid></s-query-container>
        </s-box>
    );
}

/** The few most urgent products (out of stock, then order today); the full list is on the Reorder page. */
export function UrgentList({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const { actions } = dashboard;
    const urgent = [...actions.out_of_stock, ...actions.order_today];
    const items = (urgent.length > 0 ? urgent : actions.this_week).slice(0, SHOWN);
    const total = urgent.length + actions.this_week.length;

    return (
        <s-section padding="none">
            <s-box padding="base">
                <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                    <s-heading>{urgent.length > 0 ? t('home.urgentHeading') : t('home.thisWeekHeading')}</s-heading>
                    {total > 0 && <s-link href="/reorder">{t('home.seeAll', { count: total })}</s-link>}
                </s-stack>
            </s-box>
            {items.length === 0 ? (
                <s-box padding="base" borderWidth="small none none none" borderColor="base">
                    <s-text color="subdued">{t('actions.nothingThisWeek')}</s-text>
                </s-box>
            ) : (
                items.map((item) => <Row key={item.variant_id} item={item} />)
            )}
        </s-section>
    );
}
