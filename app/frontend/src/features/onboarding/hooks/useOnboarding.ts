import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { onboardingApi } from '@/features/onboarding/api/onboardingApi';
import { shopKeys } from '@/features/shop/hooks/useShop';

export function useOnboarding() {
    return useQuery({ queryKey: ['onboarding'], queryFn: onboardingApi.get });
}

export function useCompleteOnboarding() {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: onboardingApi.complete,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: shopKeys.all });
            queryClient.invalidateQueries({ queryKey: ['setup-guide'] });
        },
    });
}
