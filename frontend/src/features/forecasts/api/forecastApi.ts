import { http } from '@/lib/http';
import type {
    ForecastDetail,
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
};
