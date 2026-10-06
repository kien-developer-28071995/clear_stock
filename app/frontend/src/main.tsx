import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app/App';
import { ErrorBoundary } from '@/components/layout/ErrorBoundary';
import { initI18n } from '@/i18n';
import { installErrorReporting } from '@/lib/errorReporting';
import { installWebVitals } from '@/lib/webVitals';

// Opened outside the Shopify admin: with a shop (an old link, straight after install) continue
// inside the admin; without one there is nothing to show, the website introduces the app.
const query = new URLSearchParams(window.location.search);
const shop = query.get('shop');
if (window.top === window.self && query.get('embedded') !== '1') {
    if (shop && /^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i.test(shop)) {
        window.location.replace(`https://${shop}/admin/apps/${import.meta.env.VITE_SHOPIFY_API_KEY}${window.location.pathname}`);
        await new Promise(() => undefined);
    } else if (import.meta.env.PROD && import.meta.env.VITE_WEBSITE_URL) {
        window.location.replace(import.meta.env.VITE_WEBSITE_URL);
        await new Promise(() => undefined);
    }
}

installErrorReporting();
installWebVitals();

// Speak the merchant's Shopify admin language (falls back to English).
await initI18n(shopify.config.locale);

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <ErrorBoundary>
            <App />
        </ErrorBoundary>
    </StrictMode>,
);
