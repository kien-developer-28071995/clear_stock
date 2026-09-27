export type BudgetReason = 'out_of_stock' | 'runs_out_before_delivery' | 'reorder_point';

export interface BudgetItem {
    variant_id: number;
    name: string;
    sku: string | null;
    supplier: string | null;
    abc_class: 'A' | 'B' | 'C' | null;
    stockout_date: string | null;
    quantity: number;
    unit_cost: number | null;
    cost: number | null;
    reason: BudgetReason;
    priority: number;
    /** Fits in the budget left (products without a cost always count in). */
    in_budget: boolean;
}

export interface BudgetPlan {
    today: string;
    month: string;
    currency: string | null;
    budget: number | null;
    /** Orders marked as placed this month (manual or emailed), at current unit cost. */
    spent: { orders: number; cost: number; missing_cost: number };
    remaining: number | null;
    totals: { products: number; cost: number; in_budget: number; in_budget_cost: number; waiting: number; waiting_cost: number; missing_cost: number };
    items: BudgetItem[];
}
