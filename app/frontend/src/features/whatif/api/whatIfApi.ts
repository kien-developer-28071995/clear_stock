import { http } from '@/lib/http';
import type { WhatIf, WhatIfParams } from '@/features/whatif/types';

const query = (params: WhatIfParams) => {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && q.set(k, String(v)));
    return q;
};

export const whatIfApi = {
    get: async (params: WhatIfParams) => (await http.get<{ data: WhatIf }>(`/what-if?${query(params)}`)).data,
    /** The scenario's orders as a CSV download. */
    export: (params: WhatIfParams) => http.download(`/what-if/export?${query(params)}`),
};
