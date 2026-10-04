import { useQuery } from '@tanstack/react-query';
import { reportsApi } from '@/features/reports/api/reportsApi';

/** Under ['forecasts'] so they refresh with every forecast or product change. */
export function useStockHistory(days: number) {
    return useQuery({ queryKey: ['forecasts', 'stock-history', days], queryFn: () => reportsApi.stockHistory(days) });
}

export function useClearance() {
    return useQuery({ queryKey: ['forecasts', 'clearance'], queryFn: reportsApi.clearance });
}

export function useSizeRuns() {
    return useQuery({ queryKey: ['forecasts', 'size-runs'], queryFn: reportsApi.sizeRuns });
}

/** `recheck` after the merchant granted the scope: the backend re-reads the granted scopes once. */
export function useShopifyPurchaseOrders(enabled: boolean, recheck: boolean) {
    return useQuery({ queryKey: ['shopify-purchase-orders', recheck], queryFn: () => reportsApi.shopifyPurchaseOrders(recheck), enabled });
}

export function useDataHealth() {
    return useQuery({ queryKey: ['forecasts', 'data-health'], queryFn: reportsApi.dataHealth });
}
