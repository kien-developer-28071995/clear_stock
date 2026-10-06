import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { budgetApi } from '@/features/budget/api/budgetApi';

/** Under ['forecasts'] so every forecast change refreshes it. */
const key = ['forecasts', 'budget'] as const;

export function useBudget(enabled = true) {
    return useQuery({ queryKey: key, queryFn: budgetApi.get, enabled });
}

export function useSetBudget() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: budgetApi.set,
        onSuccess: (data) => {
            qc.setQueryData(key, data);
            qc.invalidateQueries({ queryKey: ['forecasts', 'purchase-plan'] });
        },
    });
}
