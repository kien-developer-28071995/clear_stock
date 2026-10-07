import { http } from '@/lib/http';
import type { PeakSuggestion, SalesEvent, SalesEventInput } from '@/features/events/types';

export const salesEventApi = {
    list: async () => (await http.get<{ data: SalesEvent[] }>('/sales-events')).data,
    suggestions: async () => (await http.get<{ data: PeakSuggestion[] }>('/sales-events/suggestions')).data,
    create: async (body: SalesEventInput) => (await http.post<{ data: SalesEvent }>('/sales-events', body)).data,
    update: async ({ id, ...body }: SalesEventInput & { id: number }) => (await http.put<{ data: SalesEvent }>(`/sales-events/${id}`, body)).data,
    remove: (id: number) => http.delete<null>(`/sales-events/${id}`),
};
