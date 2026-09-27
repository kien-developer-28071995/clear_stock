import { useQuery } from '@tanstack/react-query';
import { dashboardApi } from '@/features/dashboard/api/dashboardApi';

export function useDashboard() {
    return useQuery({ queryKey: ['dashboard'], queryFn: dashboardApi.get });
}

/** Under ['forecasts'] so it refreshes with every forecast change. */
export function useAccuracy(enabled = true) {
    return useQuery({ queryKey: ['forecasts', 'accuracy'], queryFn: dashboardApi.accuracy, enabled });
}
