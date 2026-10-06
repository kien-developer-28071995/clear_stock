import { http } from '@/lib/http';
import type { CostImportResult, CostList } from '@/features/costs/types';

export const costApi = {
    list: async (params: { missing: boolean; search: string }) => {
        const q = new URLSearchParams();
        if (params.missing) q.set('missing', '1');
        if (params.search) q.set('search', params.search);
        return (await http.get<{ data: CostList }>(`/costs?${q}`)).data;
    },
    update: async (items: { variant_id: number; cost: number | null }[]) => (await http.put<{ data: { updated: number } }>('/costs', { items })).data,
    import: async (file: File) => {
        const form = new FormData();
        form.append('file', file);
        return (await http.postForm<{ data: CostImportResult }>('/costs/import', form)).data;
    },
};
