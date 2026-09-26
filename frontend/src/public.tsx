import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';
import { initI18n } from '@/i18n';
import { PrivacyPage } from '@/features/legal/pages/PrivacyPage';
import { SupportPage } from '@/features/legal/pages/SupportPage';
import '@/features/legal/legal.css';

/**
 * Public pages (privacy policy, support): a separate bundle from the embedded app, without
 * App Bridge, since they are opened outside the Shopify admin (App Store listing, emails).
 * Language: ?lang=vi, else the browser's.
 */
await initI18n(navigator.language, { explicit: new URLSearchParams(window.location.search).get('lang') });

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <BrowserRouter>
            <Routes>
                <Route path="/privacy" element={<PrivacyPage />} />
                <Route path="/support" element={<SupportPage />} />
                <Route path="*" element={<Navigate to="/support" replace />} />
            </Routes>
        </BrowserRouter>
    </StrictMode>,
);
