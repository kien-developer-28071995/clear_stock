/**
 * Crisp live chat (crisp.chat). Off unless VITE_CRISP_WEBSITE_ID is set at build time: then the
 * widget loads once the page is idle, so it never competes with the app's own first paint.
 */
const WEBSITE_ID: string | undefined = import.meta.env.VITE_CRISP_WEBSITE_ID;

type CrispCommand = [string, string, unknown];

declare global {
    interface Window {
        $crisp?: CrispCommand[] | { push: (command: CrispCommand) => void };
        CRISP_WEBSITE_ID?: string;
        CRISP_RUNTIME_CONFIG?: { locale: string };
    }
}

export interface SupportChatContext {
    shop: string;
    plan: string;
    locale: string;
}

let loading = false;

/** Tells the support agent which shop and plan is writing; nothing else about the shop is sent. */
export function startSupportChat(context: SupportChatContext): void {
    if (!WEBSITE_ID) return;

    window.$crisp ??= [];
    window.$crisp.push(['set', 'session:data', [[['shop', context.shop], ['plan', context.plan]]]]);

    if (loading) return;
    loading = true;
    window.CRISP_WEBSITE_ID = WEBSITE_ID;
    window.CRISP_RUNTIME_CONFIG = { locale: context.locale };

    const load = () => {
        const script = document.createElement('script');
        script.src = 'https://client.crisp.chat/l.js';
        script.async = true;
        document.head.appendChild(script);
    };
    if (window.requestIdleCallback) window.requestIdleCallback(load);
    else setTimeout(load, 1500);
}
