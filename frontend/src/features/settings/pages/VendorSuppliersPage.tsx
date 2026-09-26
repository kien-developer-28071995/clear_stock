import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { LoadingPage } from '@/components/ui/LoadingPage';
import { useSuppliersFromVendors, useVendorCandidates } from '@/features/settings/hooks/useSettings';
import type { VendorImportResult } from '@/features/settings/types';
import { formatNumber } from '@/utils/format';

/**
 * One step from the Vendor field of Shopify products to suppliers: tick vendors, and
 * each becomes a supplier (or reuses the one with that name) with its products linked.
 */
export function VendorSuppliersPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { data, isPending, error, refetch } = useVendorCandidates();
    const apply = useSuppliersFromVendors();
    const [picked, setPicked] = useState<Set<string>>(new Set());
    const [replace, setReplace] = useState(false);

    // Everything ticked except the store's own brand.
    useEffect(() => {
        if (data) setPicked(new Set(data.filter((v) => !v.is_store_name).map((v) => v.vendor)));
    }, [data]);

    if (isPending) return <LoadingPage heading={t('vendors.heading')} />;

    const toggle = (vendor: string) => {
        const next = new Set(picked);
        if (next.has(vendor)) next.delete(vendor);
        else next.add(vendor);
        setPicked(next);
    };
    const chosen = (data ?? []).filter((v) => picked.has(v.vendor));
    const products = chosen.reduce((sum, v) => sum + v.products, 0);
    const linked = chosen.reduce((sum, v) => sum + v.with_supplier, 0);

    const submit = () =>
        apply.mutate(
            { vendors: [...picked], replace_existing: replace },
            {
                onSuccess: (result) => {
                    const r = result as VendorImportResult;
                    shopify.toast.show(t('vendors.done', { suppliers: r.suppliers_created + r.suppliers_reused, count: r.products_assigned }));
                    navigate('/suppliers');
                },
            },
        );

    return (
        <s-page heading={t('vendors.heading')}>
            <s-link slot="breadcrumb-actions" href="/suppliers">{t('nav.suppliers')}</s-link>
            {(error || apply.error) && <ErrorBanner error={error ?? apply.error} onRetry={error ? () => refetch() : undefined} />}

            {data && data.length === 0 ? (
                <s-section>
                    <s-empty-state heading={t('vendors.emptyHeading')}>
                        <s-paragraph slot="subheading">{t('vendors.emptyBody')}</s-paragraph>
                    </s-empty-state>
                </s-section>
            ) : (
                <>
                    <s-section>
                        <s-paragraph>{t('vendors.intro')}</s-paragraph>
                    </s-section>
                    <s-section padding="none">
                        <s-table>
                            <s-table-header-row>
                                <s-table-header listSlot="primary">{t('products.vendor')}</s-table-header>
                                <s-table-header format="numeric">{t('nav.products')}</s-table-header>
                                <s-table-header>{t('vendors.result')}</s-table-header>
                            </s-table-header-row>
                            <s-table-body>
                                {data?.map((v) => (
                                    <s-table-row key={v.vendor}>
                                        <s-table-cell>
                                            <s-checkbox label={v.vendor} checked={picked.has(v.vendor) || undefined} onChange={() => toggle(v.vendor)} />
                                        </s-table-cell>
                                        <s-table-cell>{formatNumber(v.products, 0)}</s-table-cell>
                                        <s-table-cell>
                                            <s-stack direction="inline" gap="small-200">
                                                {v.is_store_name && <s-badge>{t('vendors.ownBrand')}</s-badge>}
                                                {v.supplier ? (
                                                    <s-badge tone="info">{t('vendors.reuses', { name: v.supplier.name })}</s-badge>
                                                ) : (
                                                    <s-badge tone="success">{t('vendors.newSupplier')}</s-badge>
                                                )}
                                                {v.with_supplier > 0 && <s-text color="subdued">{t('vendors.alreadyLinked', { count: v.with_supplier })}</s-text>}
                                            </s-stack>
                                        </s-table-cell>
                                    </s-table-row>
                                ))}
                            </s-table-body>
                        </s-table>
                    </s-section>
                    <s-section>
                        <s-stack gap="base">
                            <s-paragraph>{t('vendors.summary', { vendors: chosen.length, count: products })}</s-paragraph>
                            {linked > 0 && (
                                <s-checkbox
                                    label={t('vendors.replace', { count: linked })}
                                    checked={replace || undefined}
                                    onChange={(e) => setReplace(e.currentTarget.checked)}
                                />
                            )}
                            <s-stack direction="inline">
                                <s-button variant="primary" onClick={submit} loading={apply.isPending || undefined} disabled={chosen.length === 0 || undefined}>
                                    {t('vendors.apply', { count: chosen.length })}
                                </s-button>
                            </s-stack>
                        </s-stack>
                    </s-section>
                </>
            )}
        </s-page>
    );
}
