import { ApiError, http } from '@/lib/http';

/**
 * Sends JavaScript errors to the API, which logs them like server errors (so they
 * reach the monitoring Slack channel). API errors are not sent: the server has
 * already reported its own failures, and 4xx responses are expected.
 */
const recent = new Map<string, number>();
const ONCE_PER_MS = 60_000;

export function reportClientError(error: unknown, component?: string): void {
    if (error instanceof ApiError) return;
    const e = error instanceof Error ? error : new Error(String(error));
    if (/ResizeObserver loop|Script error\.?$/.test(e.message)) return; // browser noise without useful detail

    const key = `${e.message}|${component ?? ''}`;
    const now = Date.now();
    if ((recent.get(key) ?? 0) > now - ONCE_PER_MS) return;
    recent.set(key, now);

    http.post('/client-errors', {
        message: e.message.slice(0, 2000),
        stack: e.stack?.slice(0, 8000) ?? null,
        url: location.pathname,
        component: component?.slice(0, 2000) ?? null,
    }).catch(() => {
        // Reporting must never cause another error.
    });
}

/** Uncaught errors and unhandled promise rejections anywhere in the app. */
export function installErrorReporting(): void {
    window.addEventListener('error', (event) => reportClientError(event.error ?? event.message));
    window.addEventListener('unhandledrejection', (event) => reportClientError(event.reason));
}
