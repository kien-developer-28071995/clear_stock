import { http } from '@/lib/http';
import type {
    AlternateSupplier,
    ChangeEntry,
    ForecastDetail,
    SavedView,
    ForecastFilters,
    ForecastListRow,
    OverridesInput,
    Paginated,
    VariantSettingsInput,
    SettingsImportResult,
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
    changes: async (variantId: number) => (await http.get<{ data: ChangeEntry[] }>(`/forecasts/${variantId}/changes`)).data,
    /** days = null brings the products back now. */
    snooze: async (body: { variant_ids: number[]; days: number | null }) =>
        (await http.post<{ data: { updated: number; until: string | null } }>('/snooze', body)).data,
    views: async () => (await http.get<{ data: SavedView[] }>('/views')).data,
    saveView: async (body: { name: string; filters: Record<string, string> }) => (await http.post<{ data: SavedView[] }>('/views', body)).data,
    deleteView: async (id: number) => (await http.delete<{ data: SavedView[] }>(`/views/${id}`)).data,
    importSettings: async ({ file, apply }: { file: File; apply: boolean }) => {
        const form = new FormData();
        form.append('file', file);
        if (apply) form.append('apply', '1');
        return (await http.postForm<{ data: SettingsImportResult }>('/variants/settings/import', form)).data;
    },
};
