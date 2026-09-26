import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { bundleApi, settingsApi, supplierApi, variantApi } from '@/features/settings/api/settingsApi';

export const settingsKeys = {
    settings: ['settings'] as const,
    suppliers: ['suppliers'] as const,
    bundles: ['bundles'] as const,
};

/** Queries derived from these inputs: forecasts (recompute is queued server-side) and setup guide steps. */
const forecastQueries = [['dashboard'], ['forecasts'], ['setup-guide']];

export function useSettings() {
    return useQuery({ queryKey: settingsKeys.settings, queryFn: settingsApi.get });
}

export function useUpdateSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: settingsApi.update,
        onSuccess: (data) => {
            qc.setQueryData(settingsKeys.settings, data);
            forecastQueries.forEach((queryKey) => qc.invalidateQueries({ queryKey }));
        },
    });
}

export function useSuppliers() {
    return useQuery({ queryKey: settingsKeys.suppliers, queryFn: supplierApi.list });
}

function useSupplierMutation<T>(fn: (arg: T) => Promise<unknown>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: settingsKeys.suppliers });
            forecastQueries.forEach((queryKey) => qc.invalidateQueries({ queryKey }));
        },
    });
}

export const useCreateSupplier = () => useSupplierMutation(supplierApi.create);
export const useUpdateSupplier = () => useSupplierMutation(supplierApi.update);
export const useDeleteSupplier = () => useSupplierMutation(supplierApi.remove);
export const useAssignSupplier = () => useSupplierMutation(variantApi.bulkSettings);

export function useBundles() {
    return useQuery({ queryKey: settingsKeys.bundles, queryFn: bundleApi.list });
}

function useBundleMutation<T>(fn: (arg: T) => Promise<unknown>) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: fn,
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: settingsKeys.bundles });
            forecastQueries.forEach((queryKey) => qc.invalidateQueries({ queryKey }));
        },
    });
}

export const useSaveBundle = () => useBundleMutation(bundleApi.save);
export const useDeleteBundle = () => useBundleMutation(bundleApi.remove);
