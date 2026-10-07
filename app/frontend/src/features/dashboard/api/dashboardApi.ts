import { http } from '@/lib/http';
import type { AccuracyReport, Dashboard, SampleForecast } from '@/features/dashboard/types';

export const dashboardApi = {
    get: async () => (await http.get<{ data: Dashboard }>('/dashboard')).data,
    samples: async () => (await http.get<{ data: SampleForecast[] }>('/sample-forecasts')).data,
    accuracy: async () => (await http.get<{ data: AccuracyReport }>('/accuracy')).data,
};
