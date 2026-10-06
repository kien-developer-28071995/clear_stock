import type { AbcClass } from '@/features/forecasts/types';

export type Horizon = 0 | 14 | 30;

export interface WhatIfParams {
    /** Sales change in percent (-90..500). */
    growth: number;
    /** Count orders due today (0) or within the next 14 / 30 days. */
    horizon: Horizon;
    supplier_id?: number | '';
    vendor?: string;
    abc?: AbcClass | '';
}

/** One side of the comparison (current forecast or scenario). */
export interface WhatIfSide {
    avg: number;
    order_date: string | null;
    stockout_date: string | null;
    /** Units ordered on the order date (0 when no order is due in the horizon). */
    order_qty: number;
    /** Would run out before an order placed today arrives. */
    stockout_risk: boolean;
}

export interface WhatIfItem {
    variant_id: number;
    name: string;
    sku: string | null;
    supplier: string | null;
    unit_cost: number | null;
    lead_time_days: number;
    now: WhatIfSide;
    scenario: WhatIfSide;
}

export interface WhatIfTotals {
    products: number;
    order_today: number;
    units: number;
    cost: number;
    /** Products to order without a unit cost (not in `cost`). */
    missing_cost: number;
    stockout_risk: number;
}

export interface WhatIf {
    growth_percent: number;
    factor: number;
    horizon_days: Horizon;
    today: string;
    until: string;
    currency: string | null;
    totals: { now: WhatIfTotals; scenario: WhatIfTotals };
    /** First 200 products, earliest order first. */
    items: WhatIfItem[];
    items_total: number;
}
