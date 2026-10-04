/** Inventory units and value at cost, one point per day since the app started recording. */
export interface StockHistory {
    days: number;
    points: { date: string; units: number; value: number }[];
    latest: { date: string; units: number; value: number; products_in_stock: number; products_missing_cost: number } | null;
    /** First point of the period vs the latest (null with less than a week of history). */
    change: { from: string; units: number; value: number; percent: number | null } | null;
    started_on: string | null;
}

export type HealthCode =
    | 'negative_stock'
    | 'not_tracked'
    | 'missing_cost'
    | 'duplicate_sku'
    | 'missing_sku'
    | 'missing_price'
    | 'no_supplier'
    | 'default_lead_time';

export interface HealthFinding {
    code: HealthCode;
    severity: 'warning' | 'info';
    count: number;
    sample: { variant_id: number; name: string; sku: string | null }[];
}

/** Product data problems that make forecasts or money figures wrong. */
export interface DataHealth {
    checked: number;
    default_lead_time_days: number;
    findings: HealthFinding[];
}
