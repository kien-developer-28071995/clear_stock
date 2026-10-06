import { useState } from 'react';

const key = (id: string) => `clear_stock.dismissed.${id}`;

/**
 * Promotional banners must be dismissible (Built for Shopify 4.3.6). Remembered in
 * this browser only; storage may be unavailable, in which case the banner comes back.
 */
export function useDismissed(id: string): [boolean, () => void] {
    const [dismissed, setDismissed] = useState(() => {
        try {
            return localStorage.getItem(key(id)) === '1';
        } catch {
            return false;
        }
    });

    const dismiss = () => {
        setDismissed(true);
        try {
            localStorage.setItem(key(id), '1');
        } catch {
            // not remembered: fine
        }
    };

    return [dismissed, dismiss];
}
