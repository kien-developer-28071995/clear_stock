import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError, errorMessage } from '@/lib/http';
import { useCreateTransfer } from '@/features/transfers/hooks/useTransfers';
import { ensureTransferScope } from '@/features/transfers/scope';
import type { TransferRoute } from '@/features/transfers/types';
import { formatDate, formatNumber } from '@/utils/format';

/**
 * One suggested route (from A to B): the products to move, quantities editable (0 leaves a
 * product out), and "Create draft transfer" which creates it in Shopify for the merchant to
 * review and ship there.
 */
export function TransferRouteCard({ route }: { route: TransferRoute }) {
    const { t } = useTranslation();
    const create = useCreateTransfer();
    const [quantities, setQuantities] = useState<Record<number, string>>(() =>
        Object.fromEntries(route.items.map((i) => [i.variant_id, String(i.quantity)])),
    );
    // Same key while retrying the same transfer, so Shopify never creates it twice.
    const idempotencyKey = useRef(crypto.randomUUID());

    const items = route.items
        .map((i) => ({ variant_id: i.variant_id, quantity: Math.max(0, Math.floor(Number(quantities[i.variant_id] || 0))) }))
        .filter((i) => i.quantity > 0);
    const total = items.reduce((sum, i) => sum + i.quantity, 0);
    const heading = t('transfers.route', { origin: route.origin.name ?? '—', destination: route.destination.name ?? '—' });

    const submit = async () => {
        if (!(await ensureTransferScope())) {
            shopify.toast.show(t('transfers.permissionDeclined'), { isError: true });
            return;
        }
        create.mutate(
            { origin_location_id: route.origin.id, destination_location_id: route.destination.id, items, idempotency_key: idempotencyKey.current },
            {
                onSuccess: (transfer) => {
                    idempotencyKey.current = crypto.randomUUID();
                    shopify.toast.show(t('transfers.created', { name: transfer.name, count: transfer.total_units }));
                },
            },
        );
    };

    const error = create.error instanceof ApiError ? errorMessage(create.error) : create.error ? t('errors.generic') : null;

    return (
        <s-section heading={heading}>
            <s-stack gap="base">
                {error && <s-banner tone="critical">{error}</s-banner>}
                <s-table>
                    <s-table-header-row>
                        <s-table-header listSlot="primary">{t('table.product')}</s-table-header>
                        <s-table-header>{t('transfers.atDestination', { name: route.destination.name ?? '—' })}</s-table-header>
                        <s-table-header format="numeric">{t('transfers.atOrigin', { name: route.origin.name ?? '—' })}</s-table-header>
                        <s-table-header format="numeric">{t('transfers.move')}</s-table-header>
                    </s-table-header-row>
                    <s-table-body>
                        {route.items.map((item) => (
                            <s-table-row key={item.variant_id}>
                                <s-table-cell>
                                    <s-stack gap="small-100">
                                        <s-link href={`/products/${item.variant_id}`}>{item.name}</s-link>
                                        {item.sku && <s-text color="subdued">{item.sku}</s-text>}
                                    </s-stack>
                                </s-table-cell>
                                <s-table-cell>
                                    <s-stack gap="small-100">
                                        <s-text>{t('transfers.inStock', { count: item.destination_stock, qty: formatNumber(item.destination_stock, 0) })}</s-text>
                                        <s-text color="subdued">
                                            {item.destination_stock > 0 && item.destination_stockout_date
                                                ? t('transfers.runsOut', { date: formatDate(item.destination_stockout_date) })
                                                : t('transfers.outNow')}
                                        </s-text>
                                    </s-stack>
                                </s-table-cell>
                                <s-table-cell>{formatNumber(item.origin_stock, 0)}</s-table-cell>
                                <s-table-cell>
                                    <s-number-field
                                        label={t('transfers.move')}
                                        labelAccessibilityVisibility="exclusive"
                                        min={0}
                                        max={item.origin_stock}
                                        step={1}
                                        value={quantities[item.variant_id]}
                                        onInput={(e) => setQuantities({ ...quantities, [item.variant_id]: e.currentTarget.value })}
                                    />
                                </s-table-cell>
                            </s-table-row>
                        ))}
                    </s-table-body>
                </s-table>
                <s-stack direction="inline" gap="base" alignItems="center" justifyContent="space-between">
                    <s-text color="subdued">{t('transfers.total', { count: total, qty: formatNumber(total, 0) })}</s-text>
                    <s-button variant="primary" icon="transfer" onClick={submit} loading={create.isPending || undefined} disabled={total === 0 || undefined}>
                        {t('transfers.create')}
                    </s-button>
                </s-stack>
            </s-stack>
        </s-section>
    );
}
