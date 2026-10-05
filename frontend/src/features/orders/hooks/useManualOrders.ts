import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { manualOrderApi } from '@/features/orders/api/manualOrderApi';

const key = ['manual-orders'] as const;

export function useManualOrders(enabled = true) {
    return useQuery({ queryKey: key, queryFn: manualOrderApi.list, enabled });
}

/** Orders change what is on the way: refresh forecasts and the home screen too. */
function useOrderMutation<T, R>(fn: (arg: T) => Promise<R>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: () => [key, ['dashboard'], ['forecasts']].forEach((queryKey) => qc.invalidateQueries({ queryKey })),
    });
}

export const useCreateManualOrders = () => useOrderMutation(manualOrderApi.create);
export const useUpdateManualOrder = () => useOrderMutation(manualOrderApi.update);
