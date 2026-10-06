import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { whatIfApi } from '@/features/whatif/api/whatIfApi';
import type { WhatIfParams } from '@/features/whatif/types';

/** Under ['forecasts'] so every change that refreshes forecasts refreshes the scenario too. */
export function useWhatIf(params: WhatIfParams) {
    return useQuery({
        queryKey: ['forecasts', 'what-if', params],
        queryFn: () => whatIfApi.get(params),
        // Keep the last result on screen while a new growth value loads.
        placeholderData: keepPreviousData,
    });
}
