import { http } from '@/lib/http';
import type { WhatIf, WhatIfParams } from '@/features/whatif/types';

export const whatIfApi = {
    get: async (params: WhatIfParams) => {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && query.set(k, String(v)));
        return (await http.get<{ data: WhatIf }>(`/what-if?${query}`)).data;
    },
};
