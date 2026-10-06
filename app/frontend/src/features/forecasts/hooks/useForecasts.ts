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

/** Changes to one product that move its forecast: refresh everything derived from forecasts. */
function useProductMutation<T, R>(fn: (arg: T) => Promise<R>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: forecastKeys.all });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['suppliers'] });
        },
    });
}

export const useSetLocationMinimums = (variantId: number) =>
    useProductMutation((minimums: { location_id: number; min_stock: number | null }[]) => forecastApi.setLocationMinimums(variantId, minimums));
export const useSetAlternates = (variantId: number) =>
    useProductMutation((suppliers: Parameters<typeof forecastApi.setAlternates>[1]) => forecastApi.setAlternates(variantId, suppliers));
export const useMakeMainSupplier = (variantId: number) => useProductMutation((supplierId: number) => forecastApi.makeMainSupplier(variantId, supplierId));

/** What was changed on a product; loaded when its History tab is opened. */
export function useChanges(variantId: number, enabled: boolean) {
    return useQuery({ queryKey: [...forecastKeys.detail(variantId), 'changes'], queryFn: () => forecastApi.changes(variantId), enabled });
}

/** "Not now" for reorder suggestions (days = null brings them back). */
export const useSnooze = () => useProductMutation(forecastApi.snooze);

export function useSavedViews(enabled = true) {
    return useQuery({ queryKey: ['views'], queryFn: forecastApi.views, staleTime: 5 * 60_000, enabled });
}

function useViewMutation<T>(fn: (arg: T) => Promise<unknown>) {
    const qc = useQueryClient();
    return useMutation({ mutationFn: fn, onSuccess: (data) => qc.setQueryData(['views'], data) });
}

export const useSaveView = () => useViewMutation(forecastApi.saveView);
export const useDeleteView = () => useViewMutation(forecastApi.deleteView);
