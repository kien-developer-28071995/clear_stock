import { SectionTabs } from '@/components/layout/SectionTabs';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DashboardGate } from '@/features/dashboard/components/DashboardGate';
import { ActionList } from '@/features/dashboard/components/ActionList';
import type { ActionGroup, Dashboard } from '@/features/dashboard/types';
import { Tip } from '@/features/setup/components/Tip';
import { useTransfers } from '@/features/transfers/hooks/useTransfers';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';
import { NO_VALUE, fromOption, optionValue, optionsKey } from '@/utils/select';
import { useManualOrders } from '@/features/orders/hooks/useManualOrders';

/** Only the products of one vendor, to order everything from the same maker at once. */
function forVendor(dashboard: Dashboard, vendor: string): Dashboard {
    if (vendor === '') return dashboard;
    const actions = Object.fromEntries(
        Object.entries(dashboard.actions).map(([group, items]) => [group, items.filter((i) => i.vendor === vendor)]),
    ) as Record<ActionGroup, Dashboard['actions'][ActionGroup]>;

    return { ...dashboard, actions };
}

/** Everything to reorder in the next 7 days, grouped by urgency, ready to export as a purchase order. */
export function ReorderPage() {
    const { t } = useTranslation();
    const [vendor, setVendor] = useState('');
    const purchaseOrders = useFeature('purchase_orders');
    // Growth: stock that other locations can send, to move before ordering.
    const transfers = useTransfers(useEntitlements().transfers).data;
    const transferUnits = transfers?.routes.reduce((sum, r) => sum + r.total_units, 0) ?? 0;
    // Orders marked as placed outside Shopify.
    const orders = useManualOrders(useFeature('manual_orders')).data;
    const overdue = orders?.open.filter((o) => o.state === 'overdue').length ?? 0;

    return (
        <DashboardGate heading={t('nav.reorder')} group="reorder">
            {(data) => {
                const vendors = [...new Set(Object.values(data.actions).flat().map((i) => i.vendor).filter((v): v is string => !!v))].sort();

                return (
                    <s-page heading={t('nav.reorder')}><SectionTabs group="reorder" />
                        <s-link slot="breadcrumb-actions" href="/">{t('nav.home')}</s-link>
                        {overdue > 0 && (
                            <s-banner tone="warning" heading={t('orders.overdueHeading', { count: overdue })}>
                                <s-paragraph>{t('orders.overdueBody')}</s-paragraph>
                                <s-button slot="secondary-actions" href="/reorder/orders">{t('orders.review')}</s-button>
                            </s-banner>
                        )}
                        <Tip id="home_actions">{t(purchaseOrders ? 'tips.home_actions' : 'tips.home_actions_no_po')}</Tip>
                        {transferUnits > 0 && (
                            <s-banner tone="info" heading={t('transfers.beforeOrdering', { count: transferUnits, qty: transferUnits })}>
                                <s-paragraph>{t('transfers.beforeOrderingBody')}</s-paragraph>
                                <s-button slot="secondary-actions" href="/transfers">
                                    {t('transfers.see')}
                                </s-button>
                            </s-banner>
                        )}
                        {vendors.length > 1 && (
                            <s-box maxInlineSize="320px" paddingBlockEnd="base">
                                <s-select
                                    key={optionsKey(vendors.length)} label={t('products.vendor')} value={optionValue(vendor)} onChange={(e) => setVendor(fromOption(e.currentTarget.value))}>
                                    <s-option value={NO_VALUE}>{t('products.allVendors')}</s-option>
                                    {vendors.map((v) => (
                                        <s-option key={v} value={v}>{v}</s-option>
                                    ))}
                                </s-select>
                            </s-box>
                        )}
                        {/* key: a new vendor starts a fresh selection */}
                        <ActionList key={vendor} dashboard={forVendor(data, vendor)} />
                    </s-page>
                );
            }}
        </DashboardGate>
    );
}
