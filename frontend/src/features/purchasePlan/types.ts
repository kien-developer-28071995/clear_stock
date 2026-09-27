export type Weeks = 4 | 8 | 12;

export interface PurchasePlanParams {
    weeks: Weeks;
    supplier_id?: number | '';
    vendor?: string;
}

interface Spend {
    orders: number;
    units: number;
    /** At unit cost; orders without a unit cost are counted in `missing_cost` instead. */
    cost: number;
    missing_cost: number;
}

export interface PurchasePlanWeek extends Spend {
    /** First day of the 7-day week (weeks start today). */
    start: string;
}

export interface PurchasePlanSupplier extends Spend {
    supplier_id: number | null;
    /** null = products without a supplier. */
    name: string | null;
    products: number;
    first_order: string;
}

/** One order day with one supplier (supplier null = products without a supplier). */
export interface PurchasePlanCalendarEntry extends Omit<Spend, 'orders'> {
    date: string;
    supplier_id: number | null;
    supplier: string | null;
    products: number;
}

export interface PurchasePlanItem {
    variant_id: number;
    name: string;
    sku: string | null;
    supplier: string | null;
    unit_cost: number | null;
    avg: number;
    orders: { date: string; qty: number }[];
    units: number;
    cost: number | null;
}

export interface PurchasePlan {
    today: string;
    until: string;
    weeks: Weeks;
    currency: string | null;
    totals: Spend & { products: number };
    by_week: PurchasePlanWeek[];
    by_supplier: PurchasePlanSupplier[];
    /** Planned spend per calendar month. */
    by_month: (Spend & { month: string })[];
    /** Monthly purchasing budget (Starter), null when none is set. */
    budget: number | null;
    /** When to order from whom, by date. */
    calendar: PurchasePlanCalendarEntry[];
    /** First 200 products, biggest spend first. */
    items: PurchasePlanItem[];
    items_total: number;
}
