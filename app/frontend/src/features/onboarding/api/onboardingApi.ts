import { http } from '@/lib/http';
import type { OnboardingState } from '@/features/onboarding/types';

export const onboardingApi = {
    get: async () => (await http.get<{ data: OnboardingState }>('/onboarding')).data,
    complete: async (body: { lead_time_days: number; alert_email: string | null }) =>
        (await http.post<{ data: OnboardingState }>('/onboarding', body)).data,
};
