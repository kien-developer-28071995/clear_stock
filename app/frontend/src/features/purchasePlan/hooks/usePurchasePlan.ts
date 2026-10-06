import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { purchasePlanApi } from '@/features/purchasePlan/api/purchasePlanApi';
import type { PurchasePlanParams } from '@/features/purchasePlan/types';

/** Under ['forecasts'] so every change that refreshes forecasts refreshes the plan too. */
export function usePurchasePlan(params: PurchasePlanParams, enabled = true) {
    return useQuery({
        queryKey: ['forecasts', 'purchase-plan', params],
        queryFn: () => purchasePlanApi.get(params),
        placeholderData: keepPreviousData,
        enabled,
    });
}
