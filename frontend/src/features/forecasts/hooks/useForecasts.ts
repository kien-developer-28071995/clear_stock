import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { forecastApi } from '@/features/forecasts/api/forecastApi';
import type { ForecastFilters, OverridesInput, VariantSettingsInput } from '@/features/forecasts/types';

export const forecastKeys = {
    all: ['forecasts'] as const,
    list: (filters: ForecastFilters) => ['forecasts', 'list', filters] as const,
    detail: (variantId: number) => ['forecasts', 'detail', variantId] as const,
};

export function useForecastList(filters: ForecastFilters) {
    return useQuery({
        queryKey: forecastKeys.list(filters),
        queryFn: () => forecastApi.list(filters),
        placeholderData: keepPreviousData,
    });
}

/** Active locations (Growth). Empty on other plans. */
/** Vendors and product types of tracked products (list filters). */
export function useFacets() {
    return useQuery({ queryKey: ['facets'], queryFn: forecastApi.facets, staleTime: 5 * 60_000 });
}

export function useLocations(enabled: boolean) {
    return useQuery({ queryKey: ['locations'], queryFn: forecastApi.locations, enabled, staleTime: 5 * 60_000 });
}

export function useForecast(variantId: number) {
    return useQuery({ queryKey: forecastKeys.detail(variantId), queryFn: () => forecastApi.detail(variantId) });
}

export function useSetOverrides(variantId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (body: OverridesInput) => forecastApi.setOverrides(variantId, body),
        onSuccess: (data) => {
            qc.setQueryData(forecastKeys.detail(variantId), data);
            qc.invalidateQueries({ queryKey: ['forecasts', 'list'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
        },
    });
}

export function useUpdateVariantSettings(variantId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: (body: VariantSettingsInput) => forecastApi.updateSettings(variantId, body),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: forecastKeys.all });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
        },
    });
}
