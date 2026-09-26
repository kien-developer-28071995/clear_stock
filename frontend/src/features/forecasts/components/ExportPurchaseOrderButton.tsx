import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { http, ApiError, errorMessage } from '@/lib/http';
import { useEntitlements } from '@/hooks/useEntitlements';

/**
 * Growth plan: download a CSV purchase order of everything to reorder now (optionally one
 * supplier). On other plans the button is disabled and labelled with the plan, with a
 * link to the plans page (Built for Shopify 4.3.7).
 */
interface Props {
    supplierId?: number;
    locationId?: number;
    /** Only these products (e.g. rows picked on the home screen). */
    variantIds?: number[];
    label?: string;
    variant?: 'primary' | 'secondary';
}

export function ExportPurchaseOrderButton({ supplierId, locationId, variantIds, label, variant }: Props) {
    const { t } = useTranslation();
    const text = label ?? t('po.export');
    const { purchase_orders: allowed } = useEntitlements();
    const [busy, setBusy] = useState(false);

    const exportCsv = async () => {
        setBusy(true);
        try {
            const params = new URLSearchParams();
            if (supplierId) params.set('supplier_id', String(supplierId));
            if (locationId) params.set('location_id', String(locationId));
            if (variantIds?.length) params.set('variant_ids', variantIds.join(','));
            await http.download(`/purchase-orders/export${params.size ? `?${params}` : ''}`);
        } catch (e) {
            shopify.toast.show(e instanceof ApiError ? errorMessage(e) : t('errors.exportFailed'), { isError: true });
        } finally {
            setBusy(false);
        }
    };

    if (!allowed) {
        return (
            <s-stack direction="inline" gap="small-200" alignItems="center">
                <s-button disabled icon="lock" variant={variant}>
                    {t('po.locked', { label: text, plan: t('plans.names.growth') })}
                </s-button>
                <s-link href="/plans">{t('upgrade.seePlans')}</s-link>
            </s-stack>
        );
    }

    return (
        <s-button onClick={exportCsv} loading={busy || undefined} icon="export" variant={variant}>
            {text}
        </s-button>
    );
}
