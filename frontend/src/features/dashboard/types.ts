import type { Coded } from '@/types/coded';
import type { Confidence } from '@/types/forecast';

export interface ActionItem {
    variant_id: number;
    name: string;
    sku: string | null;
    vendor: string | null;
    current_stock: number;
    avg_daily_sales: number;
    days_of_cover: number | null;
    stockout_date: string | null;
    reorder_date: string | null;
    suggested_qty: number;
    confidence: Confidence;
    /** First explanation line (null when explanations are not included in the plan). */
    reason: Coded | null;
    explanation_lines: Coded[];
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

export interface SlowMover {
    variant_id: number;
    name: string;
    sku: string | null;
    stock: number;
    value: number;
    days_of_cover: number | null;
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
}
