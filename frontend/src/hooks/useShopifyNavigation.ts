import { useEffect } from 'react';
import { useNavigate } from 'react-router';

/**
 * Polaris web components with an `href` (s-link, s-button, s-clickable) dispatch a
 * `shopify:navigate` event instead of reloading the iframe. Route it through
 * React Router so in-app links are instant.
 */
export function useShopifyNavigation() {
    const navigate = useNavigate();

    useEffect(() => {
        const handler = (event: Event) => {
            const href = (event.target as HTMLElement | null)?.getAttribute('href');
            if (!href || !href.startsWith('/')) return;
            // Unsaved changes in a save bar: ask before leaving (resolves at once when there are none).
            void (shopify.saveBar?.leaveConfirmation?.() ?? Promise.resolve()).then(() => navigate(href));
        };
        document.addEventListener('shopify:navigate', handler);
        return () => document.removeEventListener('shopify:navigate', handler);
    }, [navigate]);
}
