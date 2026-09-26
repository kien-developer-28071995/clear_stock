import { useEffect, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { fieldError } from '@/lib/http';
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

    useEffect(() => {
        setName(supplier?.name ?? '');
        setEmail(supplier?.email ?? '');
        setLead(supplier?.lead_time_days?.toString() ?? '');
        create.reset();
        update.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [supplier]);

    const submit = () => {
        const body = { name: name.trim(), email: email.trim() || null, lead_time_days: lead === '' ? null : Number(lead) };
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
                <s-email-field
                    label={t('suppliers.email')}
                    details={t('suppliers.emailHelp')}
                    value={email}
                    error={fieldError(mutation.error, 'email')}
                    onInput={(e) => setEmail(e.currentTarget.value)}
                />
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
