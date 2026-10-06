import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { billingApi } from '@/features/billing/api/billingApi';

/** `refresh` re-reads the subscription from Shopify (after approving a charge). */
export function useBilling(refresh = false) {
    return useQuery({ queryKey: ['billing', refresh], queryFn: () => billingApi.get(refresh) });
}

export function useChangePlan() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: billingApi.change,
        onSuccess: (data) => {
            if (data.confirmation_url) {
                // Shopify's approval page must open at the top level, outside the app iframe.
                window.open(data.confirmation_url, '_top');
                return;
            }
            qc.invalidateQueries();
        },
    });
}
