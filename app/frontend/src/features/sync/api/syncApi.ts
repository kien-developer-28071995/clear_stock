import { http } from '@/lib/http';
import type { SyncStatus } from '@/features/sync/types';

export const syncApi = {
    status: async () => (await http.get<{ data: SyncStatus }>('/sync')).data,
    start: async () => (await http.post<{ data: SyncStatus }>('/sync')).data,
};
