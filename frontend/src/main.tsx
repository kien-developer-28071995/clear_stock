import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app/App';
import { ErrorBoundary } from '@/components/layout/ErrorBoundary';
import { initI18n } from '@/i18n';
import { installErrorReporting } from '@/lib/errorReporting';

installErrorReporting();

// Speak the merchant's Shopify admin language (falls back to English).
await initI18n(shopify.config.locale);

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <ErrorBoundary>
            <App />
        </ErrorBoundary>
    </StrictMode>,
);
