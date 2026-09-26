import { http } from '@/lib/http';
import type { TransferInput, Transfers } from '@/features/transfers/types';

export const transfersApi = {
    get: async () => (await http.get<{ data: Transfers }>('/transfers')).data,
    create: async (body: TransferInput) =>
        (await http.post<{ data: { id: number; shopify_transfer_id: number; name: string; total_units: number } }>('/transfers', body)).data,
};
