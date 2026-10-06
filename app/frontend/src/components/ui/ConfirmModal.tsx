import { useId, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';

interface Request {
    heading: string;
    body: ReactNode;
    confirmLabel: string;
    /** Label of the button that backs out (default: Cancel). */
    cancelLabel?: string;
    destructive?: boolean;
    resolve: (ok: boolean) => void;
}

/**
 * Promise-based confirmation in a Polaris modal (instead of window.confirm, which the
 * admin iframe may block and which doesn't look like Shopify).
 *
 *   const { confirm, modal } = useConfirm();
 *   if (await confirm({ heading, body, confirmLabel, destructive: true })) …
 *   return <>{modal}…</>;
 */
export function useConfirm() {
    const { t } = useTranslation();
    const id = `confirm-${useId().replace(/:/g, '')}`;
    const ref = useRef<ModalElement>(null);
    const [request, setRequest] = useState<Request | null>(null);
    const answered = useRef<Request | null>(null);

    const confirm = (options: Omit<Request, 'resolve'>) =>
        new Promise<boolean>((resolve) => {
            setRequest({ ...options, resolve });
            requestAnimationFrame(() => ref.current?.showOverlay());
        });

    // The content stays until the dialog has closed: a dialog whose content changes while it
    // closes can stay open.
    const finish = (ok: boolean) => {
        // Answered already (hiding fires onHide once more): nothing to do.
        if (!request || answered.current === request) return;
        answered.current = request;
        request.resolve(ok);
        const modal = ref.current;
        let cleared = false;
        const clear = () => {
            if (cleared) return;
            cleared = true;
            setRequest((current) => (current === request ? null : current));
        };
        modal?.addEventListener('afterhide', clear, { once: true });
        setTimeout(clear, 2000); // in case the event never comes
        modal?.hideOverlay();
    };

    const modal = (
        <s-modal ref={ref} id={id} heading={request?.heading ?? ''} onHide={() => request && finish(false)}>
            {typeof request?.body === 'string' ? <s-paragraph>{request.body}</s-paragraph> : request?.body}
            <s-button slot="primary-action" variant="primary" tone={request?.destructive ? 'critical' : undefined} onClick={() => finish(true)}>
                {request?.confirmLabel}
            </s-button>
            <s-button slot="secondary-actions" onClick={() => finish(false)}>
                {request?.cancelLabel ?? t('common.cancel')}
            </s-button>
        </s-modal>
    );

    return { confirm, modal };
}
