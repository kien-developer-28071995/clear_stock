import { http } from '@/lib/http';
import type {
    AlternateSupplier,
    ForecastDetail,
    SavedView,
    ForecastFilters,
    ForecastListRow,
    OverridesInput,
    Paginated,
    VariantSettingsInput,
} from '@/features/forecasts/types';

export const forecastApi = {
    list: (filters: ForecastFilters) => {
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([k, v]) => v !== undefined && v !== '' && params.set(k, String(v)));
        return http.get<Paginated<ForecastListRow>>(`/forecasts?${params}`);
    },
    facets: async () => (await http.get<{ data: { vendors: string[]; product_types: string[] } }>('/facets')).data,
    locations: async () => (await http.get<{ data: { id: number; name: string }[] }>('/locations')).data,
    detail: async (variantId: number) => (await http.get<{ data: ForecastDetail }>(`/forecasts/${variantId}`)).data,
    setOverrides: async (variantId: number, body: OverridesInput) =>
        (await http.put<{ data: ForecastDetail }>(`/forecasts/${variantId}/overrides`, body)).data,
    updateSettings: (variantId: number, body: VariantSettingsInput) => http.put(`/variants/${variantId}/settings`, body),
    setLocationMinimums: async (variantId: number, minimums: { location_id: number; min_stock: number | null }[]) =>
        (await http.put<{ data: ForecastDetail }>(`/forecasts/${variantId}/location-minimums`, { minimums })).data,
    setAlternates: async (variantId: number, suppliers: Omit<AlternateSupplier, 'name'>[]) =>
        (await http.put<{ data: AlternateSupplier[] }>(`/variants/${variantId}/suppliers`, { suppliers })).data,
    makeMainSupplier: (variantId: number, supplierId: number) => http.post(`/variants/${variantId}/suppliers/${supplierId}/main`),
    views: async () => (await http.get<{ data: SavedView[] }>('/views')).data,
    saveView: async (body: { name: string; filters: Record<string, string> }) => (await http.post<{ data: SavedView[] }>('/views', body)).data,
    deleteView: async (id: number) => (await http.delete<{ data: SavedView[] }>(`/views/${id}`)).data,
};
