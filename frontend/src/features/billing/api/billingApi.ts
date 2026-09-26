import { http } from '@/lib/http';
import type { BillingState, Interval } from '@/features/billing/types';
import type { Plan } from '@/features/shop/types';

export const billingApi = {
    get: async (refresh = false) => (await http.get<{ data: BillingState }>(`/billing${refresh ? '?refresh=1' : ''}`)).data,
    change: async (body: { plan: Plan; interval?: Interval }) =>
        (await http.post<{ data: { confirmation_url: string | null } }>('/billing', body)).data,
};
