import { http } from '@/lib/http';

/**
 * Sends the web vitals the Shopify admin measures for this app (LCP, CLS, INP...) to the backend,
 * so the owner sees the numbers Built for Shopify judges. Outside the admin there is nothing to report.
 */
export function installWebVitals(): void {
    const vitals = (window as unknown as { shopify?: { webVitals?: { onReport: (cb: (report: { metrics?: { name: string; value: number }[] }) => void) => void } } }).shopify?.webVitals;
    if (!vitals) return;

    vitals.onReport((report) => {
        const metrics = (report.metrics ?? []).map((m) => ({ name: m.name, value: m.value }));
        if (metrics.length === 0) return;
        // Never worth an error message or a retry.
        void http.post('/web-vitals', { page: window.location.pathname, metrics }).catch(() => undefined);
    });
}
