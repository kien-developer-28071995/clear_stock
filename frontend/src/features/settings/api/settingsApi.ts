import { http } from '@/lib/http';
import type { Bundle, BundleInput, Settings, Supplier, SupplierEmailDraft, SupplierEmailInput, SupplierInput, VendorCandidate, VendorImportResult } from '@/features/settings/types';

export const settingsApi = {
    get: async () => (await http.get<{ data: Settings }>('/settings')).data,
    update: async (body: Partial<Settings>) => (await http.put<{ data: Settings }>('/settings', body)).data,
};

export const supplierApi = {
    list: async () => (await http.get<{ data: Supplier[] }>('/suppliers')).data,
    create: async (body: SupplierInput) => (await http.post<{ data: Supplier }>('/suppliers', body)).data,
    update: async ({ id, ...body }: SupplierInput & { id: number }) =>
        (await http.put<{ data: Supplier }>(`/suppliers/${id}`, body)).data,
    remove: (id: number) => http.delete<null>(`/suppliers/${id}`),
    vendorCandidates: async () => (await http.get<{ data: { vendors: VendorCandidate[] } }>('/suppliers/from-vendors')).data.vendors,
    fromVendors: async (body: { vendors: string[]; replace_existing: boolean }) =>
        (await http.post<{ data: VendorImportResult }>('/suppliers/from-vendors', body)).data,
    emailDraft: async (id: number) => (await http.get<{ data: SupplierEmailDraft }>(`/suppliers/${id}/email`)).data,
    sendEmail: async ({ id, ...body }: SupplierEmailInput) =>
        (await http.post<{ data: { items: number; total_units: number } }>(`/suppliers/${id}/email`, body)).data,
};

export const bundleApi = {
    list: async () => (await http.get<{ data: Bundle[] }>('/bundles')).data,
    save: async (body: BundleInput) => (await http.post<{ data: Bundle }>('/bundles', body)).data,
    remove: (variantId: number) => http.delete<null>(`/bundles/${variantId}`),
};

export const variantApi = {
    bulkSettings: async (body: {
        variant_ids: (number | string)[];
        supplier_id?: number | null;
        lead_time_override?: number | null;
        safety_days?: number | null;
    }) => (await http.put<{ data: { updated: number } }>('/variants/settings', body)).data,
};
