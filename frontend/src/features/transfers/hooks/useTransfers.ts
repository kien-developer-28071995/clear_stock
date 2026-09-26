import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { transfersApi } from '@/features/transfers/api/transfersApi';

export const transferKeys = { all: ['transfers'] as const };

export function useTransfers(enabled = true) {
    return useQuery({ queryKey: transferKeys.all, queryFn: transfersApi.get, enabled });
}

export function useCreateTransfer() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: transfersApi.create,
        onSuccess: () => qc.invalidateQueries({ queryKey: transferKeys.all }),
    });
}
