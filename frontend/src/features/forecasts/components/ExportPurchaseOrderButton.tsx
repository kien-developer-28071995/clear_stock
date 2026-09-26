import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { http, ApiError } from '@/lib/http';
import { useEntitlements } from '@/hooks/useEntitlements';

/** Growth plan: download a CSV purchase order of everything to reorder now (optionally one supplier). */
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
    const navigate = useNavigate();
    const [busy, setBusy] = useState(false);

    const exportCsv = async () => {
        if (!allowed) {
            navigate('/plans');
            return;
        }
        setBusy(true);
        try {
            const params = new URLSearchParams();
            if (supplierId) params.set('supplier_id', String(supplierId));
            if (locationId) params.set('location_id', String(locationId));
            if (variantIds?.length) params.set('variant_ids', variantIds.join(','));
            await http.download(`/purchase-orders/export${params.size ? `?${params}` : ''}`);
        } catch (e) {
            shopify.toast.show(e instanceof ApiError ? e.message : t('errors.exportFailed'), { isError: true });
        } finally {
            setBusy(false);
        }
    };

    return (
        <s-button onClick={exportCsv} loading={busy || undefined} icon={allowed ? 'export' : 'lock'} variant={variant}>
            {allowed ? text : t('po.locked', { label: text, plan: t('plans.names.growth') })}
        </s-button>
    );
}
