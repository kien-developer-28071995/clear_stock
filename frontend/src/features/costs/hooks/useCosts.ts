import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { costApi } from '@/features/costs/api/costApi';

export function useCosts(params: { missing: boolean; search: string }) {
    return useQuery({ queryKey: ['costs', params], queryFn: () => costApi.list(params), placeholderData: keepPreviousData });
}

/** Costs feed every money figure: refresh the lists, dashboard and plans. */
function useCostMutation<T, R>(fn: (arg: T) => Promise<R>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: () => [['costs'], ['dashboard'], ['forecasts'], ['setup-guide']].forEach((queryKey) => qc.invalidateQueries({ queryKey })),
    });
}

export const useUpdateCosts = () => useCostMutation(costApi.update);
export const useImportCosts = () => useCostMutation(costApi.import);
