import { useModal } from '@/hooks/useModal';
import { useConfirm } from '@/components/ui/ConfirmModal';
import { useTranslation } from 'react-i18next';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { UpgradePrompt } from '@/components/ui/UpgradePrompt';
import { useEntitlements } from '@/hooks/useEntitlements';
import { BundleModal } from '@/features/settings/components/BundleModal';
import { useBundles, useDeleteBundle } from '@/features/settings/hooks/useSettings';
import type { Bundle } from '@/features/settings/types';

export function BundlesPage() {
    const { t } = useTranslation();
    const { bundles: allowed } = useEntitlements();
    const { data, isPending, error, refetch } = useBundles();
    const remove = useDeleteBundle();
    const modal = useModal();
    const { confirm, modal: confirmModal } = useConfirm();

    const confirmDelete = async (b: Bundle) => {
        const ok = await confirm({ heading: t('confirm.removeBundle'), body: t('bundles.confirmRemove', { name: b.name }), confirmLabel: t('common.remove'), destructive: true });
        if (ok) remove.mutate(b.variant_id, { onSuccess: () => shopify.toast.show(t('bundles.removed')) });
    };

    return (
        <s-page heading={t('nav.bundles')}>
            <s-button slot="primary-action" variant="primary" onClick={modal.open} disabled={!allowed || undefined}>
                {t('bundles.add')}
            </s-button>
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}
            {!allowed && (
                <UpgradePrompt plan="starter">{t('bundles.locked')}</UpgradePrompt>
            )}

            {data && data.length === 0 ? (
                <s-section>
                    <s-empty-state heading={t('bundles.emptyHeading')}>
                        <s-paragraph slot="subheading">{t('bundles.emptyBody')}</s-paragraph>
                        {allowed && <s-button slot="primary-action" onClick={modal.open}>{t('bundles.add')}</s-button>}
                    </s-empty-state>
                </s-section>
            ) : (
                <s-section padding="none">
                    <s-table loading={isPending || undefined}>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('bundles.bundle')}</s-table-header>
                            <s-table-header>{t('bundles.contains')}</s-table-header>
                            <s-table-header>{t('bundles.source')}</s-table-header>
                            <s-table-header>{t('common.actions')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data?.map((b) => (
                                <s-table-row key={b.variant_id}>
                                    <s-table-cell>{b.name}</s-table-cell>
                                    <s-table-cell>{b.components.map((c) => `${c.quantity} × ${c.name}`).join(', ')}</s-table-cell>
                                    <s-table-cell>
                                        {b.editable ? <s-badge>{t('bundles.manual')}</s-badge> : <s-badge tone="info">{t('bundles.shopify')}</s-badge>}
                                    </s-table-cell>
                                    <s-table-cell>
                                        {b.editable && (
                                            <s-button tone="critical" onClick={() => confirmDelete(b)}>
                                                {t('common.remove')}
                                            </s-button>
                                        )}
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                </s-section>
            )}

            <BundleModal modalRef={modal.ref} onDone={modal.close} />
            {confirmModal}
        </s-page>
    );
}
