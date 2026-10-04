import { useQuery } from '@tanstack/react-query';
import { reportsApi } from '@/features/reports/api/reportsApi';

/** Under ['forecasts'] so they refresh with every forecast or product change. */
export function useStockHistory(days: number) {
    return useQuery({ queryKey: ['forecasts', 'stock-history', days], queryFn: () => reportsApi.stockHistory(days) });
}

export function useDataHealth() {
    return useQuery({ queryKey: ['forecasts', 'data-health'], queryFn: reportsApi.dataHealth });
}
