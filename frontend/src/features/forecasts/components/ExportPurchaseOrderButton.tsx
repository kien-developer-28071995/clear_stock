import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { http, ApiError, errorMessage } from '@/lib/http';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';

/**
 * Growth plan: download a purchase order of everything to reorder now (optionally one
 * supplier), either as a readable CSV or in Shopify's purchase order import format
 * (Products > Purchase orders > Import; Shopify has no API to create them). On other
 * plans the button is disabled and labelled with the plan, with a link to the plans
 * page (Built for Shopify 4.3.7).
 */
interface Props {
    supplierId?: number;
    locationId?: number;
    /** Only these products (e.g. rows picked on the home screen). */
    variantIds?: number[];
    label?: string;
    variant?: 'primary' | 'secondary';
}

type Format = 'standard' | 'shopify';

export function ExportPurchaseOrderButton({ supplierId, locationId, variantIds, label, variant }: Props) {
    const { t } = useTranslation();
    const text = label ?? t('po.export');
    const menuId = `po-menu-${useId().replace(/:/g, '')}`;
    const { purchase_orders: allowed } = useEntitlements();
    const exists = useFeature('purchase_orders');
    const [busy, setBusy] = useState(false);

    const exportCsv = async (format: Format) => {
        setBusy(true);
        try {
            const params = new URLSearchParams();
            if (supplierId) params.set('supplier_id', String(supplierId));
            if (locationId) params.set('location_id', String(locationId));
            if (variantIds?.length) params.set('variant_ids', variantIds.join(','));
            if (format === 'shopify') params.set('format', 'shopify');
            const headers = await http.download(`/purchase-orders/export${params.size ? `?${params}` : ''}`);
            if (format === 'shopify') {
                const skipped = Number(headers.get('X-Skipped-Rows') ?? 0);
                shopify.toast.show(skipped > 0 ? `${t('po.shopifyDone')} ${t('po.shopifySkipped', { count: skipped })}` : t('po.shopifyDone'));
            }
        } catch (e) {
            shopify.toast.show(e instanceof ApiError ? errorMessage(e) : t('errors.exportFailed'), { isError: true });
        } finally {
            setBusy(false);
        }
    };

    if (!exists) return null;
    if (!allowed) {
        return (
            <s-stack direction="inline" gap="small-200" alignItems="center">
                <s-button disabled icon="lock" variant={variant}>
                    {t('po.locked', { label: text, plan: t('plans.names.starter') })}
                </s-button>
                <s-link href="/plans">{t('upgrade.seePlans')}</s-link>
            </s-stack>
        );
    }

    return (
        <>
            <s-button commandFor={menuId} command="--toggle" loading={busy || undefined} icon="export" variant={variant}>
                {text}
            </s-button>
            <s-menu id={menuId} accessibilityLabel={text}>
                <s-button onClick={() => exportCsv('shopify')}>{t('po.formatShopify')}</s-button>
                <s-button onClick={() => exportCsv('standard')}>{t('po.formatCsv')}</s-button>
            </s-menu>
        </>
    );
}
