import { useEffect, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { fieldError } from '@/lib/http';
import { pickVariants, type PickedVariant } from '@/lib/resourcePicker';
import { useSuppliers } from '@/features/settings/hooks/useSettings';
import { useCreateSalesEvent, useUpdateSalesEvent } from '@/features/events/hooks/useSalesEvents';
import type { EventScope, SalesEvent } from '@/features/events/types';
import { NO_VALUE, fromOption, optionValue } from '@/utils/select';

interface Props {
    modalRef: RefObject<ModalElement | null>;
    event: SalesEvent | null; // null = new
    onDone: () => void;
}

/** Change in % (+100 = double) <-> multiplier (2). */
const toPercent = (m: number) => String(Math.round((m - 1) * 100));
const toMultiplier = (p: string) => Math.round((1 + Number(p) / 100) * 100) / 100;

export function SalesEventModal({ modalRef, event, onDone }: Props) {
    const { t } = useTranslation();
    const create = useCreateSalesEvent();
    const update = useUpdateSalesEvent();
    const mutation = event ? update : create;
    const suppliers = useSuppliers().data ?? [];
    const [name, setName] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [percent, setPercent] = useState('100');
    const [scope, setScope] = useState<EventScope>('all');
    const [supplierId, setSupplierId] = useState('');
    // Picked in this dialog (gids + names), or the saved products (local ids, count only).
    const [picked, setPicked] = useState<PickedVariant[] | null>(null);

    useEffect(() => {
        setName(event?.name ?? '');
        setFrom(event?.starts_on ?? '');
        setTo(event?.ends_on ?? '');
        setPercent(event ? toPercent(event.multiplier) : '100');
        setScope(event?.applies_to ?? 'all');
        setSupplierId(event?.supplier_id ? String(event.supplier_id) : '');
        setPicked(null);
        create.reset();
        update.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [event]);

    const productCount = picked?.length ?? event?.variant_ids.length ?? 0;
    const submit = () => {
        const body = {
            name: name.trim(),
            starts_on: from,
            ends_on: to,
            multiplier: toMultiplier(percent),
            applies_to: scope,
            supplier_id: scope === 'supplier' && supplierId ? Number(supplierId) : null,
            variant_ids: scope === 'products' ? (picked ? picked.map((p) => p.gid) : event?.variant_ids ?? []) : null,
        };
        const options = { onSuccess: () => { shopify.toast.show(event ? t('events.updated') : t('events.added')); onDone(); } };
        if (event) update.mutate({ id: event.id, ...body }, options);
        else create.mutate(body, options);
    };
    const pick = async () => {
        const list = await pickVariants({ multiple: true, selected: picked?.map((p) => p.gid) });
        if (list.length > 0) setPicked(list);
    };

    return (
        <s-modal ref={modalRef} id="sales-event-modal" heading={event ? t('events.edit') : t('events.add')}>
            <s-stack gap="base">
                <s-text-field label={t('events.name')} placeholder={t('events.namePlaceholder')} value={name} error={fieldError(mutation.error, 'name')} onInput={(e) => setName(e.currentTarget.value)} />
                <s-grid gridTemplateColumns="1fr 1fr" gap="base">
                    <s-date-field label={t('events.from')} value={from} error={fieldError(mutation.error, 'starts_on')} onInput={(e) => setFrom(e.currentTarget.value)} onChange={(e) => setFrom(e.currentTarget.value)} />
                    <s-date-field label={t('events.to')} value={to} error={fieldError(mutation.error, 'ends_on')} onInput={(e) => setTo(e.currentTarget.value)} onChange={(e) => setTo(e.currentTarget.value)} />
                </s-grid>
                <s-number-field
                    label={t('events.change')}
                    suffix="%"
                    min={-90}
                    max={900}
                    step={10}
                    details={t('events.changeHelp')}
                    value={percent}
                    error={fieldError(mutation.error, 'multiplier')}
                    onInput={(e) => setPercent(e.currentTarget.value)}
                />
                <s-select label={t('events.appliesTo')} value={scope} onChange={(e) => setScope(e.currentTarget.value as EventScope)}>
                    <s-option value="all">{t('events.scope.all')}</s-option>
                    {suppliers.length > 0 && <s-option value="supplier">{t('events.scope.supplier')}</s-option>}
                    <s-option value="products">{t('events.scope.products')}</s-option>
                </s-select>
                {scope === 'supplier' && (
                    <s-select label={t('table.supplier')} value={optionValue(supplierId)} error={fieldError(mutation.error, 'supplier_id')} onChange={(e) => setSupplierId(fromOption(e.currentTarget.value))}>
                        <s-option value={NO_VALUE}>{t('events.pickSupplier')}</s-option>
                        {suppliers.map((s) => (
                            <s-option key={s.id} value={String(s.id)}>{s.name}</s-option>
                        ))}
                    </s-select>
                )}
                {scope === 'products' && (
                    <s-stack gap="small-200">
                        <s-stack direction="inline" gap="small-200" alignItems="center">
                            <s-text>{picked && picked.length <= 3 ? picked.map((p) => p.name).join(', ') : t('events.productCount', { count: productCount })}</s-text>
                            <s-button onClick={pick}>{t('events.pickProducts')}</s-button>
                        </s-stack>
                        {fieldError(mutation.error, 'variant_ids') && <s-text tone="critical">{fieldError(mutation.error, 'variant_ids')}</s-text>}
                    </s-stack>
                )}
            </s-stack>
            <s-button
                slot="primary-action"
                variant="primary"
                onClick={submit}
                loading={mutation.isPending || undefined}
                disabled={!name.trim() || !from || !to || percent === '' || Number(percent) === 0 || undefined}
            >
                {t('common.save')}
            </s-button>
            <s-button slot="secondary-actions" commandFor="sales-event-modal" command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
