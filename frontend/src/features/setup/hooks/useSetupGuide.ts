import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { setupApi } from '@/features/setup/api/setupApi';
import type { SetupGuideState, TipKey } from '@/features/setup/types';

const KEY = ['setup-guide'] as const;

export function useSetupGuide() {
    return useQuery({ queryKey: KEY, queryFn: setupApi.get });
}

/** All guide mutations return the new state; write it straight into the cache. */
function useGuideMutation<T>(fn: (arg: T) => Promise<SetupGuideState>) {
    const qc = useQueryClient();
    return useMutation({ mutationFn: fn, onSuccess: (data) => qc.setQueryData(KEY, data), meta: { silent: true } }); // progress notes: never worth an error message
}

export const useRecordSetupEvent = () => useGuideMutation(setupApi.event);
export const useSkipSetupStep = () => useGuideMutation(setupApi.skip);
export const useDismissSetupGuide = () => useGuideMutation(setupApi.dismiss);

/** Hide a tip immediately (optimistic), then persist it. */
export function useDismissTip() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: setupApi.dismissTip,
        onMutate: (tip: TipKey) =>
            qc.setQueryData<SetupGuideState>(KEY, (s) => (s ? { ...s, tips_dismissed: [...s.tips_dismissed, tip] } : s)),
        onSuccess: (data) => qc.setQueryData(KEY, data),
        meta: { silent: true },
    });
}
