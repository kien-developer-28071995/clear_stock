import { http } from '@/lib/http';
import type { SetupGuideState, SetupStepKey, TipKey } from '@/features/setup/types';

type R = { data: SetupGuideState };

export const setupApi = {
    get: async () => (await http.get<R>('/setup-guide')).data,
    event: async (event: 'viewed_forecast') => (await http.post<R>('/setup-guide/events', { event })).data,
    skip: async (step: SetupStepKey) => (await http.post<R>('/setup-guide/skip', { step })).data,
    dismiss: async (dismissed: boolean) => (await http.post<R>('/setup-guide/dismiss', { dismissed })).data,
    dismissTip: async (tip: TipKey) => (await http.post<R>('/setup-guide/tips', { tip })).data,
};
