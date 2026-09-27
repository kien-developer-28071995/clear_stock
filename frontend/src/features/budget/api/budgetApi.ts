import { http } from '@/lib/http';
import type { BudgetPlan } from '@/features/budget/types';

export const budgetApi = {
    get: async () => (await http.get<{ data: BudgetPlan }>('/budget')).data,
    set: async (budget: number | null) => (await http.put<{ data: BudgetPlan }>('/budget', { budget })).data,
};
