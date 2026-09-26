import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ModalElement } from '@/hooks/useModal';

interface Request {
    heading: string;
    body: string;
    confirmLabel: string;
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

    const confirm = (options: Omit<Request, 'resolve'>) =>
        new Promise<boolean>((resolve) => {
            setRequest({ ...options, resolve });
            requestAnimationFrame(() => ref.current?.showOverlay());
        });

    const finish = (ok: boolean) => {
        request?.resolve(ok);
        setRequest(null);
        ref.current?.hideOverlay();
    };

    const modal = (
        <s-modal ref={ref} id={id} heading={request?.heading ?? ''} onHide={() => request && finish(false)}>
            <s-paragraph>{request?.body}</s-paragraph>
            <s-button slot="primary-action" variant="primary" tone={request?.destructive ? 'critical' : undefined} onClick={() => finish(true)}>
                {request?.confirmLabel}
            </s-button>
            <s-button slot="secondary-actions" onClick={() => finish(false)}>
                {t('common.cancel')}
            </s-button>
        </s-modal>
    );

    return { confirm, modal };
}
