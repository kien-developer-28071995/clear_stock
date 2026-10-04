import { http } from '@/lib/http';
import type { DataHealth, StockHistory } from '@/features/reports/types';

export const reportsApi = {
    stockHistory: async (days: number) => (await http.get<{ data: StockHistory }>(`/stock-history?days=${days}`)).data,
    dataHealth: async () => (await http.get<{ data: DataHealth }>('/data-health')).data,
};
