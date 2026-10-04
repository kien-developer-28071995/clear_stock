import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ActionGroup, ActionItem, Dashboard } from '@/features/dashboard/types';
import { ExportPurchaseOrderButton } from '@/features/forecasts/components/ExportPurchaseOrderButton';
import { translateCode } from '@/i18n/codes';
import { daysUntil, formatDate, formatNumber } from '@/utils/format';
import { useModal } from '@/hooks/useModal';
import { MarkOrderedModal } from '@/features/orders/components/MarkOrderedModal';

const GROUPS: { key: ActionGroup; tone: 'critical' | 'warning' | 'neutral' }[] = [
    { key: 'out_of_stock', tone: 'critical' },
    { key: 'order_today', tone: 'warning' },
    { key: 'this_week', tone: 'neutral' },
];

const VISIBLE = 5;

function Row({ item, today, checked, onToggle }: { item: ActionItem; today: string; checked: boolean; onToggle: () => void }) {
    const { t } = useTranslation();
    const days = daysUntil(item.stockout_date, today);
    const stock =
        item.current_stock <= 0
            ? t('actions.noneLeft')
            : days === null
              ? t('actions.unitsLeft', { count: item.current_stock })
              : t('actions.daysLeft', { count: days });

    return (
        <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base">
            <s-grid gridTemplateColumns="auto minmax(0, 1fr)" gap="base" alignItems="center">
                <s-checkbox label={t('actions.select')} labelAccessibilityVisibility="exclusive" checked={checked || undefined} onChange={onToggle} />
                {/* Narrow screens: stock and order quantity move under the product name. */}
                <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 460px) 1fr auto, 1fr" gap="small-200" alignItems="center">
                    <s-stack gap="small-100">
                        <s-link href={`/products/${item.variant_id}`}>{item.name}</s-link>
                        <s-text color="subdued">{item.reason ? translateCode('explanation', item.reason) : t('actions.sellsPerDay', { rate: formatNumber(item.avg_daily_sales, 1) })}</s-text>
                    </s-stack>
                    <s-stack direction="inline" gap="base" alignItems="start">
                        <s-text tone={item.current_stock <= 0 ? 'critical' : undefined}>{stock}</s-text>
                        <s-stack gap="small-100">
                            <s-text type="strong">{t('actions.order', { qty: formatNumber(item.suggested_qty, 0) })}</s-text>
                            {item.reorder_date && item.reorder_date > today && (
                                <s-text color="subdued">{t('actions.orderBy', { date: formatDate(item.reorder_date) })}</s-text>
                            )}
                        </s-stack>
                    </s-stack>
                </s-grid></s-query-container>
            </s-grid>
        </s-box>
    );
}

/**
 * What to do today, most urgent first. Rows that need ordering now start selected so
 * "Export purchase order" produces today's order in one click.
 */
export function ActionList({ dashboard }: { dashboard: Dashboard }) {
    const { t } = useTranslation();
    const { actions, today } = dashboard;
    const initial = useMemo(
        () => new Set([...actions.out_of_stock, ...actions.order_today].map((i) => i.variant_id)),
        [actions],
    );
    const [selected, setSelected] = useState<Set<number>>(initial);
    const [expanded, setExpanded] = useState<Partial<Record<ActionGroup, boolean>>>({});
    const markModal = useModal();
    const all = useMemo(() => Object.values(actions).flat(), [actions]);
    const toMark = useMemo(
        () => all.filter((i) => selected.has(i.variant_id) && i.suggested_qty > 0).map((i) => ({ variant_id: i.variant_id, name: i.name, quantity: i.suggested_qty })),
        [all, selected],
    );

    const toggle = (id: number) => {
        const next = new Set(selected);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        setSelected(next);
    };

    const groups = GROUPS.filter((g) => actions[g.key].length > 0);
    if (groups.length === 0) {
        return (
            <s-section>
                <s-paragraph>{t('actions.nothingThisWeek')}</s-paragraph>
            </s-section>
        );
    }

    return (
        <s-section padding="none">
            <s-box padding="base">
                <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                    <s-text color="subdued">{t('actions.selected', { count: selected.size })}</s-text>
                    <s-stack direction="inline" gap="small-200">
                        <s-button disabled={toMark.length === 0 || undefined} onClick={() => markModal.open()}>
                            {t('orders.markSelected')}
                        </s-button>
                        <ExportPurchaseOrderButton variantIds={[...selected]} variant="primary" />
                    </s-stack>
                </s-stack>
            </s-box>
            <MarkOrderedModal id="mark-ordered-selected" modalRef={markModal.ref} items={toMark} onDone={() => setSelected(new Set())} />
            {groups.map((g) => {
                const items = actions[g.key];
                const shown = expanded[g.key] ? items : items.slice(0, VISIBLE);
                return (
                    <s-box key={g.key}>
                        <s-box padding="small-200 base" background="subdued" borderWidth="small none none none" borderColor="base">
                            <s-stack direction="inline" gap="small-200" alignItems="center">
                                <s-badge tone={g.tone}>{t('actions.groupTitle', { title: t(`actions.groups.${g.key}.title`), count: items.length })}</s-badge>
                                <s-text color="subdued">{t(`actions.groups.${g.key}.hint`)}</s-text>
                            </s-stack>
                        </s-box>
                        {shown.map((item) => (
                            <Row key={item.variant_id} item={item} today={today} checked={selected.has(item.variant_id)} onToggle={() => toggle(item.variant_id)} />
                        ))}
                        {items.length > VISIBLE && !expanded[g.key] && (
                            <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base">
                                <s-link onClick={() => setExpanded({ ...expanded, [g.key]: true })}>{t('actions.showMore', { count: items.length - VISIBLE })}</s-link>
                            </s-box>
                        )}
                    </s-box>
                );
            })}
            {dashboard.actions_truncated && (
                <s-box padding="small-200 base" borderWidth="small none none none" borderColor="base">
                    <s-link href="/products?status=reorder_now">{t('actions.seeAll')}</s-link>
                </s-box>
            )}
        </s-section>
    );
}
