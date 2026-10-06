import { http } from '@/lib/http';
import type { AccuracyReport, Dashboard } from '@/features/dashboard/types';

export const dashboardApi = {
    get: async () => (await http.get<{ data: Dashboard }>('/dashboard')).data,
    accuracy: async () => (await http.get<{ data: AccuracyReport }>('/accuracy')).data,
};
