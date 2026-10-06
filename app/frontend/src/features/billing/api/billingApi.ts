import { http } from '@/lib/http';
import type { BillingState, Interval } from '@/features/billing/types';
import type { Plan } from '@/features/shop/types';
import type { Coded } from '@/types/coded';

export const billingApi = {
    get: async (refresh = false) => (await http.get<{ data: BillingState }>(`/billing${refresh ? '?refresh=1' : ''}`)).data,
    /** What a smaller plan takes away (codes + the shop's numbers), for the confirmation dialog. */
    impact: async (plan: Plan) => (await http.get<{ data: { downgrade: boolean; lost: Coded[] } }>(`/billing/impact?plan=${plan}`)).data,
    change: async (body: { plan: Plan; interval?: Interval }) =>
        (await http.post<{ data: { confirmation_url: string | null } }>('/billing', body)).data,
};
