import { useEffect, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { fieldError } from '@/lib/http';
import { useEntitlements, useFeature } from '@/hooks/useEntitlements';
import { useCreateSupplier, useUpdateSupplier } from '@/features/settings/hooks/useSettings';
import type { Supplier } from '@/features/settings/types';

interface Props {
    modalRef: RefObject<ModalElement | null>;
    supplier: Supplier | null; // null = new
    onDone: () => void;
}

export function SupplierModal({ modalRef, supplier, onDone }: Props) {
    const { t } = useTranslation();
    const create = useCreateSupplier();
    const update = useUpdateSupplier();
    const mutation = supplier ? update : create;
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [lead, setLead] = useState('');
    const [minOrder, setMinOrder] = useState('');
    const [pack, setPack] = useState('');
    const [cycle, setCycle] = useState('');
    const [autoEmail, setAutoEmail] = useState(false);
    // Emailing an order by hand is Starter; automatic weekly orders are Growth.
    const canAutoEmail = useEntitlements().supplier_auto_email;
    const emailsExist = useFeature('supplier_emails');

    useEffect(() => {
        setName(supplier?.name ?? '');
        setEmail(supplier?.email ?? '');
        setLead(supplier?.lead_time_days?.toString() ?? '');
        setMinOrder(supplier?.min_order_qty?.toString() ?? '');
        setPack(supplier?.pack_size?.toString() ?? '');
        setCycle(supplier?.order_cycle_days?.toString() ?? '');
        setAutoEmail(supplier?.auto_email ?? false);
        create.reset();
        update.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supplier]);

    const submit = () => {
        const body = {
            name: name.trim(),
            email: email.trim() || null,
            lead_time_days: lead === '' ? null : Number(lead),
            min_order_qty: minOrder === '' ? null : Number(minOrder),
            pack_size: pack === '' ? null : Number(pack),
            order_cycle_days: cycle === '' ? null : Number(cycle),
            ...(canAutoEmail ? { auto_email: autoEmail && email.trim() !== '' } : {}),
        };
        const options = { onSuccess: () => { shopify.toast.show(supplier ? t('suppliers.updated') : t('suppliers.added')); onDone(); } };
        if (supplier) update.mutate({ id: supplier.id, ...body }, options);
        else create.mutate(body, options);
    };

    return (
        <s-modal ref={modalRef} id="supplier-modal" heading={supplier ? t('suppliers.edit') : t('suppliers.add')}>
            <s-stack gap="base">
                <s-text-field label={t('suppliers.name')} value={name} error={fieldError(mutation.error, 'name')} onInput={(e) => setName(e.currentTarget.value)} />
                <s-number-field
                    label={t('suppliers.leadTime')}
                    suffix={t('common.daysSuffix')}
                    min={0}
                    details={t('suppliers.leadTimeHelp')}
                    value={lead}
                    error={fieldError(mutation.error, 'lead_time_days')}
                    onInput={(e) => setLead(e.currentTarget.value)}
                />
                <s-grid gridTemplateColumns="1fr 1fr" gap="base">
                    <s-number-field
                        label={t('productSettings.minOrder')}
                        min={1}
                        placeholder={t('productSettings.none')}
                        value={minOrder}
                        error={fieldError(mutation.error, 'min_order_qty')}
                        onInput={(e) => setMinOrder(e.currentTarget.value)}
                    />
                    <s-number-field
                        label={t('productSettings.packSize')}
                        min={1}
                        placeholder={t('productSettings.none')}
                        value={pack}
                        error={fieldError(mutation.error, 'pack_size')}
                        onInput={(e) => setPack(e.currentTarget.value)}
                    />
                </s-grid>
                <s-text color="subdued">{t('suppliers.orderRulesHelp')}</s-text>
                <s-number-field
                    label={t('suppliers.orderCycle')}
                    suffix={t('common.daysSuffix')}
                    min={1}
                    max={365}
                    placeholder={t('suppliers.orderCycleDefault', { count: 30 })}
                    details={t('suppliers.orderCycleHelp')}
                    value={cycle}
                    error={fieldError(mutation.error, 'order_cycle_days')}
                    onInput={(e) => setCycle(e.currentTarget.value)}
                />
                <s-email-field
                    label={t('suppliers.email')}
                    details={t('suppliers.emailHelp')}
                    value={email}
                    error={fieldError(mutation.error, 'email')}
                    onInput={(e) => setEmail(e.currentTarget.value)}
                />
                {emailsExist && (<s-checkbox
                    label={t('supplierEmail.auto')}
                    details={canAutoEmail ? t('supplierEmail.autoHelp') : t('plans.lockedHint', { plan: t('plans.names.growth') })}
                    checked={(autoEmail && email.trim() !== '') || undefined}
                    disabled={!canAutoEmail || email.trim() === '' || undefined}
                    error={fieldError(mutation.error, 'auto_email')}
                    onChange={(e) => setAutoEmail(e.currentTarget.checked)}
                />)}
            </s-stack>
            <s-button slot="primary-action" variant="primary" onClick={submit} loading={mutation.isPending || undefined} disabled={!name.trim() || undefined}>
                {t('common.save')}
            </s-button>
            <s-button slot="secondary-actions" commandFor="supplier-modal" command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
