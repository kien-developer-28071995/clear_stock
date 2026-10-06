import { useRef } from 'react';

export type ModalElement = HTMLElementTagNameMap['s-modal'];

/** Imperative handle for an <s-modal>: attach `ref`, then call open()/close(). */
export function useModal() {
    const ref = useRef<ModalElement>(null);
    return {
        ref,
        open: () => ref.current?.showOverlay(),
        close: () => ref.current?.hideOverlay(),
    };
}
