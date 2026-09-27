import type { Coded } from '@/types/coded';
import type { Confidence, ForecastStatus } from '@/types/forecast';

/** A = the products making up the first 80% of recent revenue, B = up to 95%, C = the rest. */
export type AbcClass = 'A' | 'B' | 'C';

export interface ForecastRow {
    variant_id: number;
    name: string;
    product_title: string;
    variant_title: string | null;
    sku: string | null;
    /** Shopify product vendor / product type (filters, suppliers from vendors). */
    vendor: string | null;
    product_type: string | null;
    unit_cost: number | null;
    supplier: { id: number; name: string } | null;
    is_bundle: boolean;
    /** Current selling price (shop currency). */
    price: number | null;
    /** ABC class from the last `days` of revenue at the current price; class null without a price. */
    /** null when ABC classes are switched off app-wide. */
    abc: { class: AbcClass | null; revenue: number; share: number; days: number } | null;
    current_stock: number;
    /** On the way (Shopify incoming: purchase orders, transfers); already counted in suggested_qty. */
    incoming_stock: number;
    avg_daily_sales: number;
    days_of_cover: number | null;
    stockout_date: string | null;
    reorder_date: string | null;
    reorder_point: number;
    suggested_qty: number;
    /** Order-up-to level, and stock (+ on the way) above it. */
    target_stock: number;
    excess_units: number;
    confidence: Confidence;
    status: ForecastStatus;
    computed_at: string;
}

/** One row of the product list: only what the table shows (the detail page loads the rest). */
export type ForecastListRow = Pick<
    ForecastRow,
    | 'variant_id'
    | 'name'
    | 'sku'
    | 'vendor'
    | 'status'
    | 'current_stock'
    | 'incoming_stock'
    | 'avg_daily_sales'
    | 'days_of_cover'
    | 'reorder_date'
    | 'suggested_qty'
    | 'excess_units'
> & { abc_class: AbcClass | null };

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
    avg_source: 'computed' | 'override' | 'reference';
    computed_avg: number;
    lead_time: { days: number; source: string; supplier?: string };
    safety: { days: number; source: string; units: number };
    reorder: {
        point: number;
        date: string | null;
        suggested_qty: number;
        order_cycle_days: number;
        lead_time_demand: number;
        /** Reorder point from the forecast, before a manual minimum. */
        computed_point?: number;
        min_stock?: number | null;
        max_stock?: number | null;
    };
    confidence: { level: Confidence; reasons: { code: string }[] };
}

export interface ForecastDetail extends ForecastRow {
    /** null on plans without explanations */
    explanation: Explanation | null;
    /** Explanation as {code, params} lines, translated by the app. */
    explanation_lines: Coded[];
    explanation_locked: boolean;
    computed_avg: number;
    /** Growth plan only: stock and (when computed) the forecast per location. */
    locations: LocationForecast[] | null;
    overrides: Record<OverrideField, Override | null>;
    settings: {
        supplier_id: number | null;
        lead_time_override: number | null;
        safety_days: number | null;
        /** Supplier minimum order (units); suggestions are raised to it. */
        min_order_qty: number | null;
        /** Units per case; suggestions are rounded up to whole cases. */
        pack_size: number | null;
        /** Manual reorder point (stock + on the way); null = from the forecast. */
        min_stock: number | null;
        /** Manual order-up-to level; null = from the forecast. */
        max_stock: number | null;
        /** No alert email (digest or real-time) mentions this product. */
        alerts_muted: boolean;
        /** No longer reordered: sells through what is left (no suggestion, alert or plan). */
        discontinued: boolean;
        /** New products: similar product whose sales rate is borrowed, and the share of it (null = 100%). */
        reference_variant_id: number | null;
        reference_name: string | null;
        reference_percent: number | null;
    };
    defaults: { lead_time_days: number; safety_days: number };
    /** Latest judged week: the forecast then vs what really sold per in-stock day (null until there is one). */
    accuracy: {
        week_start: string;
        week_end: string;
        predicted: number;
        actual: number;
        in_stock_days: number;
        source: 'computed' | 'override' | 'reference';
    } | null;
}

export interface LocationForecast {
    location_id: number;
    location: string;
    available: number;
    forecast: {
        incoming_stock: number;
        avg_daily_sales: number;
        days_of_cover: number | null;
        stockout_date: string | null;
        reorder_date: string | null;
        reorder_point: number;
        suggested_qty: number;
        confidence: Confidence;
        explanation_lines: Coded[];
    } | null;
}

export interface ForecastFilters {
    location_id?: number | '';
    status?: ForecastStatus | '';
    vendor?: string;
    product_type?: string;
    abc?: AbcClass | '';
    search?: string;
    sort?: 'urgency' | 'cover' | 'name' | 'suggested' | 'value' | 'revenue';
    page?: number;
}

/** Send `null` to remove an override, omit a field to leave it unchanged. */
export type OverridesInput = Partial<Record<OverrideField, { value: number | null; note?: string | null; expires_at?: string | null } | null>>;

export interface VariantSettingsInput {
    supplier_id?: number | null;
    lead_time_override?: number | null;
    safety_days?: number | null;
    min_order_qty?: number | null;
    pack_size?: number | null;
    min_stock?: number | null;
    max_stock?: number | null;
    alerts_muted?: boolean;
    discontinued?: boolean;
    /** Local id or Shopify variant gid (resource picker); null removes it. */
    reference_variant?: number | string | null;
    reference_percent?: number | null;
}
