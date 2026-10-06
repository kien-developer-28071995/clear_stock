import { http } from '@/lib/http';

export const feedbackApi = {
    send: (body: { message: string; email: string | null }) => http.post<{ data: { sent: boolean } }>('/feedback', body),
    /** What Shopify's review dialog answered (see useReviewPrompt). */
    reviewPrompt: (code: string) => http.post('/review-prompt', { code }),
};
