import { useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { feedbackApi } from '@/features/feedback/api/feedbackApi';
import { shopKeys, useShop } from '@/features/shop/hooks/useShop';
import { useFeature } from '@/hooks/useEntitlements';

/** One request per page load, whatever Shopify answers. */
let askedThisSession = false;

/** Time for the task's own dialog to close and its toast to be read. */
const DELAY_MS = 2500;

/**
 * Asks for an App Store review with Shopify's own dialog (App Bridge reviews API), at the end of
 * a finished task: call the returned function after an order was marked as placed or a purchase
 * order was exported. The backend decides whether this shop may be asked at all (once, after a
 * week of use, while syncing works); Shopify adds its own limits and may not show the dialog.
 * Nothing here is tied to a "rate us" button, a reward or a feature.
 */
export function useReviewPrompt(): () => void {
    const eligible = useShop().data?.review_prompt ?? false;
    const exists = useFeature('review_prompt');
    const qc = useQueryClient();

    return useCallback(() => {
        if (!eligible || !exists || askedThisSession) return;
        askedThisSession = true;
        window.setTimeout(async () => {
            try {
                const result = await shopify.reviews.request();
                await feedbackApi.reviewPrompt(result.code);
                qc.invalidateQueries({ queryKey: shopKeys.all });
            } catch {
                // An older admin without the reviews API, or the report failed: ask again another day.
            }
        }, DELAY_MS);
    }, [eligible, exists, qc]);
}
