import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useShopifyPurchaseOrders } from '@/features/reports/hooks/useReports';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';
import { useShop } from '@/features/shop/hooks/useShop';
import { formatDate, formatMoney, formatNumber } from '@/utils/format';

/**
 * Purchase orders created in Shopify itself (Products > Purchase orders) that are ordered and
 * open. Their units already count as on the way (Shopify's "incoming"); this shows what they are.
 * Needs an optional permission, asked for here the first time.
 */
export function ShopifyPurchaseOrders() {
    const { t } = useTranslation();
    const exists = useFeature('shopify_purchase_orders');
    const allowed = useEntitlements().shopify_purchase_orders;
    const [recheck, setRecheck] = useState(false);
    const { data, isFetching } = useShopifyPurchaseOrders(exists && allowed, recheck);
    const currency = useShop().data?.currency ?? null;
    if (!exists || !allowed || !data) return null;

    const connect = async () => {
        const response = await shopify.scopes.request([data.scope]);
        if (response.result === 'granted-all') setRecheck(true);
        else shopify.toast.show(t('shopifyPo.declined'), { isError: true });
    };

    return (
        <s-section heading={t('shopifyPo.heading')} padding={data.scope_granted && data.orders.length > 0 ? 'none' : undefined}>
            {!data.scope_granted ? (
                <s-stack gap="small-200">
                    <s-paragraph>{t('shopifyPo.connectBody')}</s-paragraph>
                    <s-stack direction="inline">
                        <s-button loading={isFetching || undefined} onClick={connect}>{t('shopifyPo.connect')}</s-button>
                    </s-stack>
                </s-stack>
            ) : data.error ? (
                <s-text color="subdued">{t('shopifyPo.error')}</s-text>
            ) : data.orders.length === 0 ? (
                <s-text color="subdued">{t('shopifyPo.none')}</s-text>
            ) : (
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('shopifyPo.order')}</s-table-header>
                        <s-table-header>{t('table.supplier')}</s-table-header>
                        <s-table-header>{t('shopifyPo.orderedOn')}</s-table-header>
                        <s-table-header format="numeric">{t('orders.quantity')}</s-table-header>
                        <s-table-header format="currency">{t('shopifyPo.cost')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {data.orders.map((o) => (
                            <s-table-row key={o.id}>
                                <s-table-cell>
                                    <s-stack gap="small-100">
                                        <s-text type="strong">{o.name}</s-text>
                                        <s-text color="subdued">
                                            {o.lines.slice(0, 3).map((l) => `${formatNumber(l.quantity, 0)} × ${l.name ?? l.supplier_sku ?? '—'}`).join(', ')}
                                            {o.lines.length > 3 ? ` +${o.lines.length - 3}` : ''}
                                        </s-text>
                                    </s-stack>
                                </s-table-cell>
                                <s-table-cell>{o.supplier ?? '—'}</s-table-cell>
                                <s-table-cell>{formatDate(o.ordered_on)}</s-table-cell>
                                <s-table-cell>{formatNumber(o.units, 0)}</s-table-cell>
                                <s-table-cell>{formatMoney(o.cost, o.currency ?? currency)}</s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
            )}
        </s-section>
    );
}
