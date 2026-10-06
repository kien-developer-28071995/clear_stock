import { useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { useSubmitOnce } from '@/hooks/useSubmitOnce';
import type { ModalElement } from '@/hooks/useModal';
import { useSnooze } from '@/features/forecasts/hooks/useForecasts';
import { formatDate } from '@/utils/format';

interface Item {
    variant_id: number;
    name: string;
}

interface Props {
    id: string;
    modalRef: RefObject<ModalElement | null>;
    items: Item[];
    onDone?: () => void;
}

const CHOICES = [7, 14, 30, 60];

/**
 * "Not now": the picked products leave the reorder list and alert emails for some days, then come
 * back by themselves. Their forecast does not change.
 */
export function SnoozeModal({ id, modalRef, items, onDone }: Props) {
    const { t } = useTranslation();
    const snooze = useSnooze();
    const once = useSubmitOnce();
    // From submit until the dialog has closed it keeps showing what was submitted (the list behind it refreshes).
    const [submitted, setSubmitted] = useState<Item[] | null>(null);
    const shown = submitted ?? items;
    const [days, setDays] = useState(String(CHOICES[0]));

    const submit = () => once((done) => {
        setSubmitted(items);
        snooze.mutate(
            { variant_ids: items.map((i) => i.variant_id), days: Number(days) },
            {
                onSuccess: (r) => {
                    shopify.toast.show(t('snooze.done', { count: r.updated, date: formatDate(r.until) }));
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
        <s-modal ref={modalRef} id={id} heading={t('snooze.heading')} onShow={() => setDays(String(CHOICES[0]))}>
            <s-stack gap="base">
                <s-paragraph>{shown.length === 1 ? t('snooze.introOne', { name: shown[0].name }) : t('snooze.intro', { count: shown.length })}</s-paragraph>
                <s-select label={t('snooze.for')} value={days} onChange={(e) => setDays(e.currentTarget.value)}>
                    {CHOICES.map((d) => (
                        <s-option key={d} value={String(d)}>{t('common.dayCount', { count: d })}</s-option>
                    ))}
                </s-select>
                <s-text color="subdued">{t('snooze.help')}</s-text>
            </s-stack>
            <s-button slot="primary-action" variant="primary" onClick={submit} loading={snooze.isPending || submitted !== null || undefined} disabled={shown.length === 0 || undefined}>
                {t('snooze.confirm')}
            </s-button>
            <s-button slot="secondary-actions" commandFor={id} command="--hide">
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );
}
