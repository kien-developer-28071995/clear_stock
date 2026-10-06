import { SectionTabs } from '@/components/layout/SectionTabs';
import { useConfirm } from '@/components/ui/ConfirmModal';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { useManualOrders, useUpdateManualOrder } from '@/features/orders/hooks/useManualOrders';
import { ShopifyPurchaseOrders } from '@/features/orders/components/ShopifyPurchaseOrders';
import type { ManualOrder, ManualOrderState } from '@/features/orders/types';
import { formatDate, formatNumber } from '@/utils/format';

const TONE: Record<ManualOrderState, 'info' | 'warning' | 'critical' | 'success' | 'neutral'> = {
    open: 'info',
    late: 'warning',
    overdue: 'critical',
    received: 'success',
    cancelled: 'neutral',
};

const CLOSED_SHOWN = 8;

/** Orders placed outside Shopify: counted as on the way until received, cancelled or overdue. */
export function ManualOrdersPage() {
    const { t } = useTranslation();
    const { data, error, refetch } = useManualOrders();
    const update = useUpdateManualOrder();
    const [showClosed, setShowClosed] = useState(false);
    const { confirm, modal: confirmModal } = useConfirm();
    // Partial delivery: the row being edited and the units received so far.
    const [part, setPart] = useState<{ id: number; value: string } | null>(null);

    if (!data) return error ? <s-page heading={t('nav.reorder')}><SectionTabs group="reorder" /><ErrorBanner error={error} onRetry={() => refetch()} /></s-page> : <LoadingPage heading={t('nav.reorder')} group="reorder" />;

    const act = (o: ManualOrder, status: 'received' | 'cancelled' | 'open') =>
        update.mutate({ id: o.id, status }, { onSuccess: () => shopify.toast.show(t(`orders.done.${status}`)) });
    // Cancelling changes what gets suggested, and the button sits where others just were: ask first.
    const cancel = async (o: ManualOrder) => {
        const ok = await confirm({
            heading: t('orders.confirmCancelHeading'),
            body: t('orders.confirmCancel', { name: o.name ?? '', qty: formatNumber(o.quantity, 0) }),
            confirmLabel: t('orders.cancel'),
            cancelLabel: t('orders.keep'),
            destructive: true,
        });
        if (ok) act(o, 'cancelled');
    };
    const savePart = (o: ManualOrder) => {
        const received = Number(part?.value);
        if (!Number.isFinite(received) || received < 0) return;
        update.mutate(
            { id: o.id, received_quantity: received },
            { onSuccess: (saved) => { setPart(null); shopify.toast.show(t(saved.state === 'received' ? 'orders.done.received' : 'orders.done.part')); } },
        );
    };
    const overdue = data.open.filter((o) => o.state === 'overdue');

    const table = (rows: ManualOrder[], open: boolean) => (
        <s-table>
            <s-table-header-row>
                <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                <s-table-header format="numeric">{t('orders.quantity')}</s-table-header>
                <s-table-header>{t('orders.expected')}</s-table-header>
                <s-table-header>{t('orders.reference')}</s-table-header>
                <s-table-header>{t('orders.status')}</s-table-header>
                {open && <s-table-header>{t('common.actions')}</s-table-header>}
            </s-table-header-row>
            <s-table-body>
                {rows.map((o) => (
                    <s-table-row key={o.id}>
                        <s-table-cell>
                            <s-stack gap="small-100">
                                <s-link href={`/products/${o.variant_id}`}>{o.name ?? '—'}</s-link>
                                <s-text color="subdued">{[o.sku, o.supplier, t('orders.orderedOn', { date: formatDate(o.ordered_on) })].filter(Boolean).join(' · ')}</s-text>
                            </s-stack>
                        </s-table-cell>
                        <s-table-cell>
                            <s-stack gap="small-100" alignItems="end">
                                <s-text>{formatNumber(o.quantity, 0)}</s-text>
                                {(o.state === 'open' || o.state === 'late' || o.state === 'overdue') && o.received_quantity > 0 && (
                                    <s-text color="subdued">{t('orders.partReceived', { received: formatNumber(o.received_quantity, 0) })}</s-text>
                                )}
                            </s-stack>
                        </s-table-cell>
                        <s-table-cell>{formatDate(o.expected_on)}</s-table-cell>
                        <s-table-cell>{o.source === 'supplier_email' ? t('orders.viaEmail') : o.reference ?? '—'}</s-table-cell>
                        <s-table-cell><s-badge tone={TONE[o.state]}>{t(`orders.state.${o.state}`)}</s-badge></s-table-cell>
                        {open && (
                            <s-table-cell>
                                {part?.id === o.id ? (
                                    <s-stack direction="inline" gap="small-200" alignItems="end">
                                        <s-box maxInlineSize="120px">
                                            <s-number-field
                                                label={t('orders.receivedSoFar')}
                                                min={0}
                                                max={o.quantity}
                                                value={part.value}
                                                onInput={(e) => setPart({ id: o.id, value: e.currentTarget.value })}
                                            />
                                        </s-box>
                                        <s-button loading={update.isPending || undefined} onClick={() => savePart(o)}>{t('common.save')}</s-button>
                                        <s-button variant="tertiary" onClick={() => setPart(null)}>{t('common.cancel')}</s-button>
                                    </s-stack>
                                ) : (
                                <>
                                    {/* One action in sight, the rest under "More": three buttons a row is a wall on a phone. */}
                                    <s-stack direction="inline" gap="small-200" alignItems="center">
                                        <s-button onClick={() => act(o, 'received')}>{t('orders.receive')}</s-button>
                                        <s-button accessibilityLabel={t('suppliers.moreActions', { name: o.name ?? '' })} commandFor={`order-menu-${o.id}`} command="--toggle">
                                            {t('common.more')}
                                        </s-button>
                                    </s-stack>
                                    <s-menu id={`order-menu-${o.id}`} accessibilityLabel={t('suppliers.moreActions', { name: o.name ?? '' })}>
                                        <s-button onClick={() => setPart({ id: o.id, value: String(o.received_quantity || '') })}>{t('orders.receivePart')}</s-button>
                                        <s-button tone="critical" onClick={() => void cancel(o)}>{t('orders.cancel')}</s-button>
                                    </s-menu>
                                </>
                                )}
                            </s-table-cell>
                        )}
                    </s-table-row>
                ))}
            </s-table-body>
        </s-table>
    );

    return (
        <s-page heading={t('nav.reorder')}><SectionTabs group="reorder" />
            {overdue.length > 0 && (
                <s-banner tone="warning" heading={t('orders.overdueHeading', { count: overdue.length })}>
                    <s-paragraph>{t('orders.overdueBody')}</s-paragraph>
                </s-banner>
            )}
            <s-section>
                <s-paragraph>{t('orders.intro')}</s-paragraph>
            </s-section>
            {data.open.length === 0 ? (
                <s-section>
                    <s-empty-state heading={t('orders.emptyHeading')}>
                        <s-paragraph slot="subheading">{t('orders.emptyBody')}</s-paragraph>
                        <s-button slot="primary-action" href="/reorder">{t('nav.reorder')}</s-button>
                    </s-empty-state>
                </s-section>
            ) : (
                <s-section heading={t('orders.openHeading')} padding="none">{table(data.open, true)}</s-section>
            )}
            <ShopifyPurchaseOrders />
            {data.closed.length > 0 && (
                <s-section heading={t('orders.closedHeading')} padding="none">
                    {table(showClosed ? data.closed : data.closed.slice(0, CLOSED_SHOWN), false)}
                    {data.closed.length > CLOSED_SHOWN && !showClosed && (
                        <s-box padding="base">
                            <s-button variant="tertiary" onClick={() => setShowClosed(true)}>{t('actions.showMore', { count: data.closed.length - CLOSED_SHOWN })}</s-button>
                        </s-box>
                    )}
                </s-section>
            )}
            {confirmModal}
        </s-page>
    );
}
