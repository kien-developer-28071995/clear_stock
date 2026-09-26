import type { Coded } from '@/types/coded';

export interface ActionItem {
    variant_id: number;
    name: string;
    vendor: string | null;
    current_stock: number;
    avg_daily_sales: number;
    stockout_date: string | null;
    reorder_date: string | null;
    suggested_qty: number;
    /** First explanation line, or null when there is none. */
    reason: Coded | null;
}

export type ActionGroup = 'out_of_stock' | 'order_today' | 'this_week';

export interface RunwayItem {
    variant_id: number;
    name: string;
    days_of_cover: number;
    /** Lead time + safety days: stock needed to reorder in time. */
    reorder_days: number;
}

export interface OverstockItem {
    variant_id: number;
    name: string;
    sku: string | null;
    stock: number;
    /** Level to hold. */
    target: number;
    excess: number;
    /** Cost of the excess; null without a unit cost. */
    value: number | null;
}

export interface LostSaleItem {
    variant_id: number;
    name: string;
    sku: string | null;
    stock: number;
    out_of_stock_days: number;
    units: number;
    /** At the current selling price; null without a price. */
    revenue: number | null;
}

export interface SlowMover {
    variant_id: number;
    name: string;
    sku: string | null;
    stock: number;
    value: number;
    days_of_cover: number | null;
}

export interface AbcClassSummary {
    count: number;
    revenue: number;
    /** 0..1 of the shop's revenue in the window. */
    revenue_share: number;
    /** Stock on hand at unit cost. */
    stock_value: number;
}

export interface Dashboard {
    today: string;
    currency: string | null;
    forecasted_at: string | null;
    counts: { total: number; tracked: number; reorder_now: number; out_of_stock: number; slow: number; overstock: number; healthy: number };
    explanations_locked: boolean;
    actions: Record<ActionGroup, ActionItem[]>;
    actions_truncated: boolean;
    runway: RunwayItem[];
    slow_movers: { value: number; count: number; missing_cost: number; days: number; top: SlowMover[] };
    /** Still selling, but holding clearly more than the order-up-to level. */
    overstock: { value: number; units: number; count: number; missing_cost: number; top: OverstockItem[] };
    /** Sales missed on out-of-stock days of the last 30 days; null when switched off app-wide. */
    lost_sales: { units: number; revenue: number; count: number; missing_price: number; days: number; top: LostSaleItem[] } | null;
    /** Products, revenue and stock value per ABC class. */
    /** null when ABC classes are switched off app-wide. */
    abc: {
        classes: Record<'A' | 'B' | 'C', AbcClassSummary>;
        unclassified: number;
        missing_cost: number;
        days: number;
        thresholds: { a: number; b: number };
    } | null;
}
