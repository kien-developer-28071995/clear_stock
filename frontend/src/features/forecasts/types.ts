import type { Confidence, ForecastStatus } from '@/types/forecast';

export interface ForecastRow {
    variant_id: number;
    name: string;
    product_title: string;
    variant_title: string | null;
    sku: string | null;
    unit_cost: number | null;
    supplier: { id: number; name: string } | null;
    is_bundle: boolean;
    current_stock: number;
    avg_daily_sales: number;
    days_of_cover: number | null;
    stockout_date: string | null;
    reorder_date: string | null;
    reorder_point: number;
    suggested_qty: number;
    confidence: Confidence;
    status: ForecastStatus;
    computed_at: string;
}

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number; per_page: number };
}

export type OverrideField = 'avg_daily_sales' | 'lead_time_days' | 'safety_days';

export interface Override {
    value: number;
    note: string | null;
    expires_at: string | null;
}

export interface ExplanationWindow {
    days: number;
    in_stock_days: number;
    excluded_out_of_stock_days: number;
    units: number;
    avg: number | null;
    weight: number;
}

export interface Explanation {
    as_of: string;
    windows: ExplanationWindow[];
    base_avg: number;
    seasonality: { applied: boolean; factor: number; reason: string | null; horizon_days: number };
    bundles: { bundle_variant_id: number; name: string; quantity_per_bundle: number; units_per_day: number }[];
    avg_daily_sales: number;
    avg_source: 'computed' | 'override';
    computed_avg: number;
    lead_time: { days: number; source: string; supplier?: string };
    safety: { days: number; source: string; units: number };
    reorder: { point: number; date: string | null; suggested_qty: number; order_cycle_days: number; lead_time_demand: number };
    confidence: { level: Confidence; reasons: { code: string }[] };
}

export interface ForecastDetail extends ForecastRow {
    /** null on plans without explanations */
    explanation: Explanation | null;
    explanation_sentences: string[];
    explanation_locked: boolean;
    computed_avg: number;
    /** Growth plan only: stock and (when computed) the forecast per location. */
    locations: LocationForecast[] | null;
    overrides: Partial<Record<OverrideField, Override>>;
    settings: { supplier_id: number | null; lead_time_override: number | null; safety_days: number | null };
    defaults: { lead_time_days: number; safety_days: number };
}

export interface LocationForecast {
    location_id: number;
    location: string;
    available: number;
    forecast: {
        avg_daily_sales: number;
        days_of_cover: number | null;
        stockout_date: string | null;
        reorder_date: string | null;
        reorder_point: number;
        suggested_qty: number;
        confidence: Confidence;
        explanation_sentences: string[];
    } | null;
}

export interface ForecastFilters {
    location_id?: number | '';
    status?: ForecastStatus | '';
    search?: string;
    sort?: 'urgency' | 'cover' | 'name' | 'suggested' | 'value';
    page?: number;
}

/** Send `null` to remove an override, omit a field to leave it unchanged. */
export type OverridesInput = Partial<Record<OverrideField, { value: number | null; note?: string | null; expires_at?: string | null } | null>>;

export interface VariantSettingsInput {
    supplier_id?: number | null;
    lead_time_override?: number | null;
    safety_days?: number | null;
}
