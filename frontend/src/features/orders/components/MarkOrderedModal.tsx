import { useEffect, useState, type RefObject } from 'react';
import { useSubmitOnce } from '@/hooks/useSubmitOnce';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { fieldError } from '@/lib/http';
import { useCreateManualOrders } from '@/features/orders/hooks/useManualOrders';
import { formatNumber } from '@/utils/format';

interface Item {
    variant_id: number;
    name: string;
    quantity: number;
}

interface Props {
    id: string;
    modalRef: RefObject<ModalElement | null>;
    items: Item[];
    onDone?: () => void;
}

/**
 * "Mark as ordered" for orders placed outside Shopify: they count as on the way, so they are not
 * suggested again. One product: the quantity can be edited; several: each gets its suggested quantity.
 */
export function MarkOrderedModal({ id, modalRef, items, onDone }: Props) {
    const { t } = useTranslation();
    const create = useCreateManualOrders();
    const once = useSubmitOnce();
    // From submit until the dialog has closed, it keeps showing what was submitted: the lists behind
    // it refresh at that moment, and a dialog whose content changes while it closes stays open.
    const [submitted, setSubmitted] = useState<Item[] | null>(null);
    const shown = submitted ?? items;
    const single = shown.length === 1;
    const [qty, setQty] = useState('');
    const [expected, setExpected] = useState('');
    const [reference, setReference] = useState('');

    useEffect(() => {
        if (submitted) return;
        setQty(items.length === 1 ? String(items[0].quantity) : '');
        setExpected('');
        setReference('');
        create.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [items, submitted]);

    const units = single ? Number(qty) : shown.reduce((sum, i) => sum + i.quantity, 0);
    const submit = () => once((done) => {
        setSubmitted(items);
        create.mutate(
            {
                items: single ? [{ variant_id: items[0].variant_id, quantity: Number(qty) }] : items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
                expected_on: expected || null,
                reference: reference.trim() || null,
            },
            {
                onSuccess: (r) => {
                    shopify.toast.show(t('orders.recorded', { count: r.recorded }));
                    const modal = modalRef.current;
                    let finished = false;
                    const finish = () => {
                        if (finished) return;
                        finished = true;
                        setSubmitted(null);
                        onDone?.();
                    };
                    modal?.addEventListener('afterhide', finish, { once: true });
                    setTimeout(finish, 2000); // in case the event never comes
                    modal?.hideOverlay();
                },
                onError: () => setSubmitted(null),
                onSettled: done,
            },
        );
    });

    return (
        <s-modal ref={modalRef} id={id} heading={t('orders.markHeading')}>
            <s-stack gap="base">
                <s-paragraph>{single ? t('orders.markIntroOne', { name: shown[0]?.name ?? '' }) : t('orders.markIntro', { count: shown.length, units: formatNumber(units, 0) })}</s-paragraph>
                {single && (
                    <s-number-field label={t('orders.quantity')} min={1} value={qty} error={fieldError(create.error, 'items.0.quantity')} onInput={(e) => setQty(e.currentTarget.value)} />
                )}
                <s-query-container><s-grid gridTemplateColumns="@container (inline-size > 400px) 1fr 1fr, 1fr" gap="base">
                    <s-date-field
                        label={t('orders.expected')}
                        details={t('orders.expectedHelp')}
                        value={expected}
                        error={fieldError(create.error, 'expected_on')}
                        onInput={(e) => setExpected(e.currentTarget.value)}
                        onChange={(e) => setExpected(e.currentTarget.value)}
                    />
                    <s-text-field label={t('orders.reference')} placeholder="PO-1024" value={reference} onInput={(e) => setReference(e.currentTarget.value)} />
                </s-grid></s-query-container>
                <s-text color="subdued">{t('orders.markHelp')}</s-text>
                {fieldError(create.error, 'items') && <s-text tone="critical">{fieldError(create.error, 'items')}</s-text>}
            </s-stack>
            <s-button slot="primary-action" variant="primary" onClick={submit} loading={create.isPending || submitted !== null || undefined} disabled={shown.length === 0 || !(units > 0) || undefined}>
                {t('orders.mark')}
            </s-button>
            <s-button slot="secondary-actions" commandFor={id} command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
