import { useMutation, useQueryClient } from '@tanstack/react-query';
import { importApi } from '@/features/imports/api/importApi';

export function useImportPreview() {
    return useMutation({ mutationFn: importApi.preview });
}

export function useApplyImport() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: importApi.apply,
        onSuccess: () => {
            // Suppliers and lead times changed; forecasts are recomputed server-side.
            [['suppliers'], ['dashboard'], ['forecasts'], ['setup-guide']].forEach((queryKey) => qc.invalidateQueries({ queryKey }));
        },
    });
}
