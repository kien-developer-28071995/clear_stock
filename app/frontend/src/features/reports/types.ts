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

/** Slow and overstocked products with what sold lately: what to discount. */
export interface Clearance {
    days: number;
    currency: string | null;
    count: number;
    value: number;
    items: {
        variant_id: number;
        name: string;
        sku: string | null;
        status: 'slow' | 'overstock';
        stock: number;
        excess: number;
        days_of_cover: number | null;
        value: number | null;
        sold: number;
        /** Units sold / (units sold + still in stock). */
        sell_through: number | null;
        last_sold_on: string | null;
        days_since_sale: number | null;
    }[];
}

/** A product whose best-selling variant is short while other variants sit. */
export interface SizeRun {
    product_id: number;
    product: string;
    short: number;
    sitting: number;
    variants: { variant_id: number; title: string | null; share: number; stock: number; days_of_cover: number | null; state: 'short' | 'sitting' | 'ok' }[];
}

/** Shopify's own purchase orders that are ordered and still open. */
export interface ShopifyPurchaseOrders {
    scope_granted: boolean;
    scope: string;
    error: string | null;
    orders: {
        id: number;
        name: string;
        supplier: string | null;
        ordered_on: string | null;
        units: number;
        cost: number;
        currency: string | null;
        lines: { variant_id: number | null; name: string | null; supplier_sku: string | null; quantity: number }[];
    }[];
}
