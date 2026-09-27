import { http } from '@/lib/http';
import type { ManualOrder, ManualOrderInput, ManualOrderList } from '@/features/orders/types';

export const manualOrderApi = {
    list: async () => (await http.get<{ data: ManualOrderList }>('/manual-orders')).data,
    create: async (body: ManualOrderInput) => (await http.post<{ data: { recorded: number } }>('/manual-orders', body)).data,
    update: async ({ id, ...body }: { id: number; status?: 'open' | 'received' | 'cancelled'; expected_on?: string; quantity?: number }) =>
        (await http.patch<{ data: ManualOrder }>(`/manual-orders/${id}`, body)).data,
};
