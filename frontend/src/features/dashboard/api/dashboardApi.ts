import { http } from '@/lib/http';
import type { Dashboard } from '@/features/dashboard/types';

export const dashboardApi = {
    get: async () => (await http.get<{ data: Dashboard }>('/dashboard')).data,
};
