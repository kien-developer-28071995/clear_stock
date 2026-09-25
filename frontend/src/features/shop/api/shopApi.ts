import { http } from '@/lib/http';
import type { Shop } from '@/features/shop/types';

export const shopApi = {
    get: async () => (await http.get<{ data: Shop }>('/shop')).data,
};
