import { SectionTabs } from '@/components/layout/SectionTabs';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useModal } from '@/hooks/useModal';
import { useConfirm } from '@/components/ui/ConfirmModal';
import { ErrorBanner } from '@/components/ui/ErrorBanner';
import { SalesEventModal } from '@/features/events/components/SalesEventModal';
import { useDeleteSalesEvent, useSalesEvents } from '@/features/events/hooks/useSalesEvents';
import type { SalesEvent } from '@/features/events/types';
import { useShop } from '@/features/shop/hooks/useShop';
import { formatDate, formatNumber } from '@/utils/format';

/** Promotions and other known sales changes: upcoming ones feed the orders, past ones are left out of averages. */
export function SalesEventsPage() {
    const { t } = useTranslation();
    const { data, isPending, error, refetch } = useSalesEvents();
    const remove = useDeleteSalesEvent();
    const modal = useModal();
    const { confirm, modal: confirmModal } = useConfirm();
    const [editing, setEditing] = useState<SalesEvent | null>(null);
    const today = new Date().toLocaleDateString('en-CA', { timeZone: useShop().data?.timezone ?? undefined });

    const openNew = () => {
        setEditing(null);
        modal.open();
    };
    const openEdit = (e: SalesEvent) => {
        setEditing(e);
        modal.open();
    };
    const confirmDelete = async (e: SalesEvent) => {
        const ok = await confirm({ heading: t('events.deleteHeading'), body: t('events.confirmDelete', { name: e.name }), confirmLabel: t('common.delete'), destructive: true });
        if (ok) remove.mutate(e.id, { onSuccess: () => shopify.toast.show(t('events.deleted')) });
    };
    const change = (m: number) => `${m > 1 ? '+' : ''}${formatNumber((m - 1) * 100, 0)}%`;
    const scope = (e: SalesEvent) =>
        e.applies_to === 'supplier' ? e.supplier ?? '—' : e.applies_to === 'products' ? t('events.productCount', { count: e.variant_ids.length }) : t('events.scope.all');
    const status = (e: SalesEvent) => (e.ends_on < today ? 'past' : e.starts_on <= today ? 'now' : 'upcoming');

    return (
        <s-page heading={t('nav.planning')}><SectionTabs group="planning" />
            <s-link slot="breadcrumb-actions" href="/">{t('nav.home')}</s-link>
            <s-button slot="primary-action" variant="primary" onClick={openNew}>
                {t('events.add')}
            </s-button>
            {error && <ErrorBanner error={error} onRetry={() => refetch()} />}
            <s-section>
                <s-paragraph>{t('events.intro')}</s-paragraph>
            </s-section>

            {data && data.length === 0 ? (
                <s-section>
                    <s-empty-state heading={t('events.emptyHeading')}>
                        <s-paragraph slot="subheading">{t('events.emptyBody')}</s-paragraph>
                        <s-button slot="primary-action" onClick={openNew}>{t('events.add')}</s-button>
                    </s-empty-state>
                </s-section>
            ) : (
                <s-section padding="none">
                    <s-table loading={isPending || undefined}>
                        <s-table-header-row>
                            <s-table-header listSlot="primary">{t('events.name')}</s-table-header>
                            <s-table-header>{t('events.dates')}</s-table-header>
                            <s-table-header format="numeric">{t('events.change')}</s-table-header>
                            <s-table-header>{t('events.appliesTo')}</s-table-header>
                            <s-table-header>{t('common.actions')}</s-table-header>
                        </s-table-header-row>
                        <s-table-body>
                            {data?.map((e) => (
                                <s-table-row key={e.id}>
                                    <s-table-cell>
                                        <s-stack direction="inline" gap="small-200" alignItems="center">
                                            <s-text type="strong">{e.name}</s-text>
                                            <s-badge tone={status(e) === 'now' ? 'info' : status(e) === 'past' ? 'neutral' : 'success'}>{t(`events.status.${status(e)}`)}</s-badge>
                                            {e.repeats_yearly && <s-badge tone="info">{t('events.yearly')}</s-badge>}
                                        </s-stack>
                                    </s-table-cell>
                                    <s-table-cell>{`${formatDate(e.starts_on)} – ${formatDate(e.ends_on)}`}</s-table-cell>
                                    <s-table-cell>{change(e.multiplier)}</s-table-cell>
                                    <s-table-cell>{scope(e)}</s-table-cell>
                                    <s-table-cell>
                                        <s-button-group>
                                            <s-button slot="secondary-actions" onClick={() => openEdit(e)}>{t('common.edit')}</s-button>
                                            <s-button slot="secondary-actions" tone="critical" onClick={() => confirmDelete(e)}>{t('common.delete')}</s-button>
                                        </s-button-group>
                                    </s-table-cell>
                                </s-table-row>
                            ))}
                        </s-table-body>
                    </s-table>
                </s-section>
            )}

            <SalesEventModal modalRef={modal.ref} event={editing} onDone={modal.close} />
            {confirmModal}
        </s-page>
    );
}
