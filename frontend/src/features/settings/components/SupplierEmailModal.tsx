import { useEffect, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';
import { ApiError, errorMessage, fieldError } from '@/lib/http';
import { useSendSupplierEmail, useSupplierEmailDraft } from '@/features/settings/hooks/useSettings';
import type { Supplier, SupplierEmailItem } from '@/features/settings/types';

interface Props {
    modalRef: RefObject<ModalElement | null>;
    supplier: Supplier | null;
    onDone: () => void;
}

/**
 * Review and send a purchase order email to a supplier: the products due for this
 * supplier with the suggested quantities (editable, removable), an optional message,
 * and the address replies go to. The supplier also gets the list as a CSV file.
 */
export function SupplierEmailModal({ modalRef, supplier, onDone }: Props) {
    const { t } = useTranslation();
    const draft = useSupplierEmailDraft(supplier?.id ?? null);
    const send = useSendSupplierEmail();
    const [items, setItems] = useState<SupplierEmailItem[]>([]);
    const [message, setMessage] = useState('');
    const [replyTo, setReplyTo] = useState('');

    useEffect(() => {
        if (draft.data) {
            setItems(draft.data.items);
            setReplyTo(draft.data.reply_to ?? '');
            setMessage('');
            send.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft.data]);

    const total = items.reduce((sum, i) => sum + i.quantity, 0);
    const submit = () =>
        supplier &&
        send.mutate(
            {
                id: supplier.id,
                items: items.map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
                message: message.trim() || null,
                reply_to: replyTo.trim() || null,
            },
            {
                onSuccess: () => {
                    shopify.toast.show(t('supplierEmail.sent', { name: supplier.name }));
                    onDone();
                },
            },
        );

    const generalError = send.error instanceof ApiError && send.error.status !== 422 ? errorMessage(send.error) : null;
    const noEmail = draft.data && !draft.data.to;

    return (
        <s-modal ref={modalRef} id="supplier-email-modal" heading={t('supplierEmail.heading', { name: supplier?.name ?? '' })} size="large">
            {draft.isPending ? (
                <s-spinner accessibilityLabel={t('common.loading')} />
            ) : draft.error ? (
                <s-banner tone="critical">{errorMessage(draft.error)}</s-banner>
            ) : (
                <s-stack gap="base">
                    {noEmail && <s-banner tone="warning">{t('supplierEmail.noEmail')}</s-banner>}
                    {generalError && <s-banner tone="critical">{generalError}</s-banner>}
                    {draft.data?.to && <s-text color="subdued">{t('supplierEmail.to', { email: draft.data.to })}</s-text>}

                    {items.length === 0 ? (
                        <s-paragraph>{t('supplierEmail.nothingDue')}</s-paragraph>
                    ) : (
                        <s-stack gap="small-200">
                            {items.map((item) => (
                                <s-grid key={item.variant_id} gridTemplateColumns="minmax(0, 1fr) 120px auto" gap="small-200" alignItems="center">
                                    <s-stack gap="small-100">
                                        <s-text>{item.name}</s-text>
                                        {item.sku && <s-text color="subdued">{item.sku}</s-text>}
                                    </s-stack>
                                    <s-number-field
                                        label={t('bundles.quantity')}
                                        labelAccessibilityVisibility="exclusive"
                                        min={1}
                                        value={String(item.quantity)}
                                        onInput={(e) =>
                                            setItems(items.map((x) => (x.variant_id === item.variant_id ? { ...x, quantity: Math.max(1, Number(e.currentTarget.value) || 1) } : x)))
                                        }
                                    />
                                    <s-button
                                        variant="tertiary"
                                        icon="delete"
                                        accessibilityLabel={t('common.remove')}
                                        onClick={() => setItems(items.filter((x) => x.variant_id !== item.variant_id))}
                                    />
                                </s-grid>
                            ))}
                            <s-text type="strong">{t('supplierEmail.total', { count: total })}</s-text>
                            {fieldError(send.error, 'items') && <s-text tone="critical">{fieldError(send.error, 'items')}</s-text>}
                        </s-stack>
                    )}

                    <s-text-area
                        label={t('supplierEmail.message')}
                        placeholder={t('supplierEmail.messagePlaceholder')}
                        rows={3}
                        maxLength={2000}
                        value={message}
                        error={fieldError(send.error, 'message')}
                        onInput={(e) => setMessage(e.currentTarget.value)}
                    />
                    <s-email-field
                        label={t('supplierEmail.replyTo')}
                        details={t('supplierEmail.replyToHelp')}
                        value={replyTo}
                        error={fieldError(send.error, 'reply_to')}
                        onInput={(e) => setReplyTo(e.currentTarget.value)}
                    />
                </s-stack>
            )}
            <s-button
                slot="primary-action"
                variant="primary"
                onClick={submit}
                loading={send.isPending || undefined}
                disabled={!draft.data?.to || items.length === 0 || undefined}
            >
                {t('supplierEmail.send')}
            </s-button>
            <s-button slot="secondary-actions" commandFor="supplier-email-modal" command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
