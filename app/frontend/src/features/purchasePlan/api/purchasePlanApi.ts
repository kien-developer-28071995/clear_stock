import { http } from '@/lib/http';
import type { PurchasePlan, PurchasePlanParams } from '@/features/purchasePlan/types';

const query = (params: PurchasePlanParams) => {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && q.set(k, String(v)));
    return q;
};

export const purchasePlanApi = {
    get: async (params: PurchasePlanParams) => (await http.get<{ data: PurchasePlan }>(`/purchase-plan?${query(params)}`)).data,
    /** Every planned order as a CSV download. */
    export: (params: PurchasePlanParams) => http.download(`/purchase-plan/export?${query(params)}`),
};
