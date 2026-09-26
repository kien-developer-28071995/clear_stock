import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useModal } from '@/hooks/useModal';
import { useConfirm } from '@/components/ui/ConfirmModal';
import { pickVariants } from '@/lib/resourcePicker';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { ExportPurchaseOrderButton } from '@/features/forecasts/components/ExportPurchaseOrderButton';
import { SupplierModal } from '@/features/settings/components/SupplierModal';
import { useAssignSupplier, useDeleteSupplier, useSuppliers } from '@/features/settings/hooks/useSettings';
import type { Supplier } from '@/features/settings/types';

export function SuppliersPage() {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useSuppliers();
    const remove = useDeleteSupplier();
    const assign = useAssignSupplier();
    const modal = useModal();
    const { confirm, modal: confirmModal } = useConfirm();
    const [editing, setEditing] = useState<Supplier | null>(null);

    const openNew = () => {
        setEditing(null);
        modal.open();
    };
    const openEdit = (s: Supplier) => {
        setEditing(s);
        modal.open();
    };
    const assignProducts = async (s: Supplier) => {
        const picked = await pickVariants({ multiple: true });
        if (picked.length === 0) return;
        assign.mutate(
            { variant_ids: picked.map((p) => p.gid), supplier_id: s.id },
            { onSuccess: (r) => shopify.toast.show(t('suppliers.assigned', { count: (r as { updated: number }).updated, name: s.name })) },
        );
    };
    const confirmDelete = async (s: Supplier) => {
        const ok = await confirm({ heading: t('confirm.deleteSupplier'), body: t('suppliers.confirmDelete', { name: s.name }), confirmLabel: t('common.delete'), destructive: true });
        if (ok) remove.mutate(s.id, { onSuccess: () => shopify.toast.show(t('suppliers.deleted')) });
    };

    return (
        <s-page heading={t('nav.suppliers')}>
            <s-button slot="primary-action" variant="primary" onClick={openNew}>
                {t('suppliers.add')}
            </s-button>
            <s-button slot="secondary-actions" href="/suppliers/import">
                {t('import.fromStocky')}
            </s-button>
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}

            {data && data.length === 0 ? (
                <s-section>
                    <s-empty-state heading={t('suppliers.emptyHeading')}>
                        <s-paragraph slot="subheading">{t('suppliers.emptyBody')}</s-paragraph>
                        <s-button slot="primary-action" onClick={openNew}>{t('suppliers.add')}</s-button>
                        <s-button slot="secondary-actions" href="/suppliers/import">{t('import.fromStocky')}</s-button>
                    </s-empty-state>
                </s-section>
            ) : (
                <s-section padding="none">
                    <s-table loading={isPending || undefined}>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('table.supplier')}</s-table-header>
                            <s-table-header format="numeric">{t('suppliers.leadTime')}</s-table-header>
                            <s-table-header format="numeric">{t('nav.products')}</s-table-header>
                            <s-table-header>{t('common.actions')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data?.map((s) => (
                                <s-table-row key={s.id}>
                                    <s-table-cell>
                                        <s-stack gap="small-100">
                                            <s-text type="strong">{s.name}</s-text>
                                            {s.email && <s-text color="subdued">{s.email}</s-text>}
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>{s.lead_time_days == null ? t('suppliers.storeDefault') : t('common.dayCount', { count: s.lead_time_days })}</s-table-cell>
                                    <s-table-cell>{s.variants_count ?? 0}</s-table-cell>
                                    <s-table-cell>
                                        <s-button-group>
                                            <s-button slot="secondary-actions" onClick={() => assignProducts(s)}>{t('suppliers.assign')}</s-button>
                                            <ExportPurchaseOrderButton supplierId={s.id} label={t('po.short')} />
                                            <s-button slot="secondary-actions" onClick={() => openEdit(s)}>{t('common.edit')}</s-button>
                                            <s-button slot="secondary-actions" tone="critical" onClick={() => confirmDelete(s)}>{t('common.delete')}</s-button>
                                        </s-button-group>
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                </s-section>
            )}

            <SupplierModal modalRef={modal.ref} supplier={editing} onDone={modal.close} />
            {confirmModal}
        </s-page>
    );
}
