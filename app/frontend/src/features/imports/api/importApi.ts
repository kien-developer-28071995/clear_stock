import { http } from '@/lib/http';
import type { ApplyRequest, ImportPreview, ImportRequest, ImportResult } from '@/features/imports/types';

function form({ files, mapping }: ImportRequest): FormData {
    const data = new FormData();
    files.forEach((file) => data.append('files[]', file));
    if (mapping) data.append('mapping', JSON.stringify(mapping));
    return data;
}

export const importApi = {
    preview: async (req: ImportRequest) => (await http.postForm<{ data: ImportPreview }>('/imports/purchase-orders/preview', form(req))).data,
    apply: async (req: ApplyRequest) => {
        const data = form(req);
        data.append('suppliers', JSON.stringify(req.suppliers));
        data.append('replace_existing', req.replaceExisting ? '1' : '0');
        return (await http.postForm<{ data: ImportResult }>('/imports/purchase-orders/apply', data)).data;
    },
};
