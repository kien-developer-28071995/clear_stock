import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { salesEventApi } from '@/features/events/api/salesEventApi';

const key = ['sales-events'] as const;

export function useSalesEvents() {
    return useQuery({ queryKey: key, queryFn: salesEventApi.list });
}

/** Under the events key: adding or deleting an event changes what is left to suggest. */
export function usePeakSuggestions() {
    return useQuery({ queryKey: [...key, 'suggestions'], queryFn: salesEventApi.suggestions, retry: false });
}

/** Every change recomputes the forecasts server-side: refresh what depends on them. */
function useEventMutation<T>(fn: (arg: T) => Promise<unknown>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: () => [key, ['dashboard'], ['forecasts']].forEach((queryKey) => qc.invalidateQueries({ queryKey })),
    });
}

export const useCreateSalesEvent = () => useEventMutation(salesEventApi.create);
export const useUpdateSalesEvent = () => useEventMutation(salesEventApi.update);
export const useDeleteSalesEvent = () => useEventMutation(salesEventApi.remove);
