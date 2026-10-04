import { http } from '@/lib/http';
import type { Clearance, DataHealth, ShopifyPurchaseOrders, SizeRun, StockHistory } from '@/features/reports/types';

export const reportsApi = {
    stockHistory: async (days: number) => (await http.get<{ data: StockHistory }>(`/stock-history?days=${days}`)).data,
    dataHealth: async () => (await http.get<{ data: DataHealth }>('/data-health')).data,
    clearance: async () => (await http.get<{ data: Clearance }>('/clearance')).data,
    sizeRuns: async () => (await http.get<{ data: SizeRun[] }>('/size-runs')).data,
    shopifyPurchaseOrders: async (recheck: boolean) => (await http.get<{ data: ShopifyPurchaseOrders }>(`/shopify-purchase-orders${recheck ? '?recheck=1' : ''}`)).data,
};
