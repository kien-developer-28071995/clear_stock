import { useRef } from 'react';

/**
 * Lets one submit through at a time. A button shows `loading` only after the next render, so two
 * clicks in the same moment (a double click, Enter held down) would both send the request and
 * create the thing twice.
 *
 *   const once = useSubmitOnce();
 *   const submit = () => once((done) => create.mutate(body, { onSettled: done }));
 */
export function useSubmitOnce(): (start: (done: () => void) => void) => void {
    const busy = useRef(false);

    return (start) => {
        if (busy.current) return;
        busy.current = true;
        start(() => {
            busy.current = false;
        });
    };
}
