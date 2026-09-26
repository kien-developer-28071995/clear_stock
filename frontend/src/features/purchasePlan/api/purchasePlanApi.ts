import { http } from '@/lib/http';
import type { PurchasePlan, PurchasePlanParams } from '@/features/purchasePlan/types';

export const purchasePlanApi = {
    get: async (params: PurchasePlanParams) => {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && query.set(k, String(v)));
        return (await http.get<{ data: PurchasePlan }>(`/purchase-plan?${query}`)).data;
    },
};
