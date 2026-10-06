import { useEffect, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { syncApi } from '@/features/sync/api/syncApi';

export const syncKeys = {
    status: ['sync', 'status'] as const,
};

/** Polls every 3s while a sync is running; stops as soon as it finishes. */
export function useSyncStatus() {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: syncKeys.status,
        queryFn: syncApi.status,
        refetchInterval: (q) => (q.state.data?.status === 'running' ? 3000 : false),
    });

    // When a run finishes, everything derived from synced data is stale.
    const previous = useRef(query.data?.status);
    useEffect(() => {
        const status = query.data?.status;
        if (previous.current === 'running' && status && status !== 'running') {
            queryClient.invalidateQueries({ predicate: (q) => q.queryKey[0] !== 'sync' });
        }
        previous.current = status;
    }, [query.data?.status, queryClient]);

    return query;
}

export function useStartSync() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: syncApi.start,
        onSuccess: (data) => queryClient.setQueryData(syncKeys.status, data),
    });
}
